<?php

namespace TypechoPlugin\CommentToMail;

/**
 * CommentToMail
 * Typecho 异步评论邮件提醒插件
 * 
 * @copyright  Copyright (c) 2022 xcsoft
 * @license    GNU General Public License 3.0
 */

use \Utils\Helper;
use \Typecho\{Widget, Db};
use \TypechoPlugin\CommentToMail\lib\{Email, Comment};

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once 'PHPMailer/SMTP.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/Exception.php';

/**
 * action
 * 
 * @package CommentToMail
 */
class Action extends Widget implements \Widget\ActionInterface
{
    /** 队列状态（mail.sent 字段）：待发送 / 已发送 / 多次失败已放弃 */
    private const SENT_PENDING = 0;
    private const SENT_DONE = 1;
    private const SENT_GAVE_UP = 2;

    /** 单条队列最多失败次数 */
    private const MAX_ATTEMPTS = 5;

    /** 
     * 数据库对象 
     * 
     * @var Db  
     */
    private Db $_db;

    /** 
     * 表前缀  
     * 
     * @var string  
     */
    private string $_prefix;

    /** 
     * 插件配置信息 
     * 
     * @var \Typecho\Config
     */
    private \Typecho\Config $_cfg;

    /** 
     * 系统配置信息 
     * 
     * @var \Widget\Options
     */
    private \Widget\Options $_options;

    /** 
     * 当前登录用户 
     * 
     * @var object 
     */
    private object $_user;

    /** 
     * 模板文件目录
     * 
     *  @var string 
     */
    private string $_template_dir = __DIR__ . '/template/';

    /**
     * 邮件对象
     *
     * @var Email
     */
    private Email $_email;

    /**
     * 评论对象
     *
     * @var \TypechoPlugin\CommentToMail\lib\Comment
     */
    private \TypechoPlugin\CommentToMail\lib\Comment $_comment;

    /**
     * 入口方法
     *
     * @access public
     * @return void
     */
    public function action()
    {
        $this->init();

        $this->on($this->request->is('do=deliverMail'))->deliverMail((string) $this->request->key);  //邮件队列

        // 发测试信、改模板只允许管理员，并校验 CSRF token
        $this->_user->pass('administrator');
        Helper::security()->protect();
        $this->on($this->request->is('do=testMail'))->testMail();                           //测试邮件
        $this->on($this->request->is('do=editTheme'))->editTheme($this->request->edit);     //编辑主题
    }

    /**
     * 初始化
     *
     * @return void
     */
    public function init()
    {
        $this->_db = Db::get();
        $this->_prefix = $this->_db->getPrefix();

        $this->_user = $this->widget('\Widget\User');
        $this->_options = $this->widget('\Widget\Options');
        $this->_cfg = Helper::options()->plugin('CommentToMail');

        // 复选框一项都不勾时配置值可能为 null，统一成数组，避免 in_array 抛 TypeError
        foreach (['other', 'status', 'validate'] as $name) {
            $this->_cfg->$name = (array) $this->_cfg->$name;
        }
    }

    /**
     * 发送邮件
     * 
     * @param string $key
     * @return void
     */
    private function deliverMail(string $key): void
    {
        $expected = (string) $this->_cfg->key;
        if ($expected === '' || !hash_equals($expected, $key)) {
            $this->response->throwJson([
                'code' => -1,
                'msg' => 'Permission deniend'
            ]);
        }

        $this->response->throwJson(array_merge(['code' => 0, 'msg' => 'success'], $this->processQueue()));
    }

    /**
     * 发送队列中所有未发送的邮件
     *
     * 供 deliverMail 接口和评论提交后的后台投递共用
     *
     * @return array
     */
    public function processQueue(): array
    {
        if (!isset($this->_db)) {
            $this->init();
        }

        $count = ['all' => 0, 'success' => 0, 'fail' => 0];

        // 文件锁：同一时刻只允许一个进程处理队列，防止并发评论 / 定时任务读到同一批记录重复发信
        // 拿不到锁说明已有进程在处理，它会循环取到本次新入队的记录，这里直接返回
        $lock = @fopen(sys_get_temp_dir() . '/CommentToMail-' . md5(__DIR__) . '.lock', 'c');
        if (!$lock) {
            error_log('[CommentToMail] cannot open queue lock file, processing without lock');
        } elseif (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['count' => $count, 'locked' => true];
        }

        $tried = []; // 本轮已处理过的 id，失败的本轮不再重试
        try {
            do {
                $rows = array_filter(
                    $this->_db->fetchAll($this->_db->select('id', 'content')->from($this->_prefix . 'mail')->where('sent = ?', self::SENT_PENDING)),
                    fn($row) => !isset($tried[$row['id']])
                );

                foreach ($rows as $row) {
                    $tried[$row['id']] = true;
                    $this->deliverRow($row) ? $count['success']++ : $count['fail']++;
                    usleep(100000); //休眠100毫秒 防止QPS限制
                }
            } while ($rows); // 处理期间新入队的记录也一并发出

            //清除已发送的数据
            $this->_db->query(
                $this->_db->delete($this->_prefix . 'mail')->where('sent = ?', self::SENT_DONE)
            );
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        $count['all'] = count($tried);
        return ['count' => $count];
    }

    /**
     * 投递单条队列记录
     *
     * 成功标记为已发送；失败则把已成功的对象和失败次数写回记录，下次只重试失败的对象，
     * 超过 MAX_ATTEMPTS 次后标记为放弃（保留在表中便于排查）
     *
     * @param array $row
     * @return bool
     */
    private function deliverRow(array $row): bool
    {
        // 只允许还原队列自身写入的类型，防止对象注入
        $commentData = unserialize(base64_decode($row['content']), ['allowed_classes' => [Comment::class, \stdClass::class]]);
        if (!($commentData instanceof Comment || $commentData instanceof \stdClass)) {
            error_log('[CommentToMail] invalid queue content, id ' . $row['id']);
            $this->_db->query($this->_db->update($this->_prefix . 'mail')->rows(['sent' => self::SENT_GAVE_UP])->where('id = ?', $row['id']));
            return false;
        }

        // 将 stdClass 转换为 Comment 对象
        $this->_comment = new Comment();
        foreach (get_object_vars($commentData) as $key => $value) {
            if (property_exists($this->_comment, $key)) {
                $this->_comment->$key = $value;
            }
        }

        if ($this->processMail()) {
            $this->_db->query($this->_db->update($this->_prefix . 'mail')->rows(['sent' => self::SENT_DONE])->where('id = ?', $row['id'])); //标识为已发送
            return true;
        }

        $this->_comment->attempts++;
        $rows = ['content' => base64_encode(serialize($this->_comment))];
        if ($this->_comment->attempts >= self::MAX_ATTEMPTS) {
            error_log('[CommentToMail] give up coid ' . $this->_comment->coid . ' after ' . $this->_comment->attempts . ' attempts');
            $rows['sent'] = self::SENT_GAVE_UP;
        }
        $this->_db->query($this->_db->update($this->_prefix . 'mail')->rows($rows)->where('id = ?', $row['id']));
        return false;
    }

    /**
     * 处理发信
     *
     * @return boolean
     */
    private function processMail(): bool
    {
        $ok = true;
        $this->_email = new Email();

        //发件人邮箱
        $this->_email->from = $this->_cfg->user;
        //发件人名称
        $this->_email->fromName = $this->_cfg->fromName ? $this->_cfg->fromName : $this->_options->title;

        //向博主发邮件的标题格式
        $this->_email->titleForOwner = $this->_cfg->titleForOwner;

        //向访客发邮件的标题格式
        $this->_email->titleForGuest = $this->_cfg->titleForGuest;

        //验证博主是否接收自己的邮件
        $toMe = (in_array('to_me', $this->_cfg->other) && $this->_comment->ownerId == $this->_comment->authorId) ? true : false;

        //向博主发信
        // TODO $this->_comment->parent === '0' // parent === ‘0’ 时 为根评论
        // 如果在此处判断 会导致 别人评论别人的评论时 不会发送邮件给博主 后续fix
        if (!in_array('owner', $this->_comment->done) && in_array($this->_comment->status, $this->_cfg->status) && $this->_comment->type !== '1' && in_array('to_owner', $this->_cfg->other) && ($toMe || $this->_comment->ownerId != $this->_comment->authorId)) {
            // 收件人为文章作者；设置了“接收邮件的地址”时改用该地址
            self::widget('\Widget\Users\Author@temp' . $this->_comment->cid, ['uid' => $this->_comment->ownerId])->to($user);
            $this->_email->reciver = $this->_cfg->mail ?: $user->mail;
            $this->_email->reciverName = $user->name;

            // 设置邮件回复信息
            $this->_email->replyTo = $this->_comment->mail; //评论者的邮箱
            $this->_email->replyToName = $this->_comment->author;
            $ok = $this->checkSent($this->authorMail()->sendMail(), 'owner') && $ok;
        }

        /** 向访客发信 */
        if (!in_array('guest', $this->_comment->done) && $this->_comment->parent !== '0' && $this->_comment->status == 'approved' && in_array('to_guest', $this->_cfg->other)) {
            /**  如果联系我的邮件地址为空，则使用文章作者的邮件地址 */
            if (!$this->_cfg->contactme) {
                if (!isset($user) || !$user) {
                    self::widget('\Widget\Users\Author@temp' . $this->_comment->cid, array('uid' => $this->_comment->ownerId))->to($user);
                }
                $this->_comment->contactme = $user->mail;
            } else {
                $this->_comment->contactme = $this->_cfg->contactme;
            }

            // 查询被回复的评论，包含状态检查以避免处理已删除的评论
            $original = $this->_db->fetchRow($this->_db->select('author', 'mail', 'text', 'status')->from('table.comments')->where('coid = ? AND status = ?', $this->_comment->parent, 'approved'));
            
            // 被评论者 - 增加原评论存在性检查
            if (!$original) {
                // 记录原评论不存在的情况（可能已被删除）
                error_log("[CommentToMail] Warning: Original comment (ID: {$this->_comment->parent}) not found or not approved for reply notification");
            }
            
            if ($original && (in_array('to_me', $this->_cfg->other) || $this->_comment->mail != ($original['mail'] ?? ''))) {
                // 安全地处理原评论数据，防止 null 值
                $this->_comment->originalText   = $original['text'] ?? '[原评论已删除]';
                $this->_comment->originalAuthor = $original['author'] ?? '匿名用户';
                $this->_comment->originalMail   = $original['mail'] ?? '';

                // 检查收件人邮箱是否有效
                $receiverMail = $original['mail'] ?? '';
                if (!empty($receiverMail) && filter_var($receiverMail, FILTER_VALIDATE_EMAIL)) {
                    $this->_email->reciver = $receiverMail;
                    $this->_email->reciverName = $original['author'] ?? '匿名用户';
                    $this->_email->replyTo  = $this->_comment->mail; //当前评论者的邮箱
                    $this->_email->replyToName = $this->_comment->author ? $this->_comment->author : $this->_options->title;
                    $ok = $this->checkSent($this->guestMail()->sendMail(), 'guest') && $ok;
                } else {
                    // 记录无效邮箱的情况
                    error_log("[CommentToMail] Warning: Invalid or empty receiver email address for original comment (ID: {$this->_comment->parent}): '{$receiverMail}'");
                }
            }
        }

        unset($this->_email); //销毁对象（评论对象由 deliverRow 写回队列，不在此销毁）
        // 只有实际发送成功（或无需发送）才算完成；失败的保留在队列里等待下次重试
        return $ok;
    }

    /**
     * 检查 sendMail 的结果：成功记入 done（重试时跳过），失败记录日志
     *
     * @param bool|string|null $result sendMail 返回值：true 表示成功，字符串为错误信息
     * @param string $target owner|guest
     * @return bool
     */
    private function checkSent($result, string $target): bool
    {
        if ($result === true) {
            $this->_comment->done[] = $target;
            return true;
        }
        error_log('[CommentToMail] send to ' . $target . ' failed (coid ' . ($this->_comment->coid ?? '?') . '): ' . (is_string($result) ? $result : 'unknown error'));
        return false;
    }

    /**
     * 作者邮件信息
     * @return $this
     */
    private function authorMail()
    {
        $date = new \Typecho\Date($this->_comment->created);
        $status = [
            "approved" => '通过',
            "waiting"  => '待审',
            "spam"     => '垃圾'
        ];
        $vars = [
            '{{siteTitle}}' => $this->_options->title,
            '{{title}}'     => $this->_comment->title,
            '{{author}}'    => $this->_comment->author,
            '{{ip}}'        => $this->_comment->ip,
            '{{mail}}'      => $this->_comment->mail,
            '{{permalink}}' => $this->_comment->permalink,
            '{{manage}}'    => $this->_options->siteUrl . __TYPECHO_ADMIN_DIR__ . "manage-comments.php",
            '{{text}}'      => $this->_comment->text,
            '{{time}}'      => $date->format('Y-m-d H:i:s'),
            '{{status}}'    => $status[$this->_comment->status] ?? $this->_comment->status,
        ];

        $this->_email->msgHtml = $this->renderHtml($this->getTemplate('owner'), $vars, ['{{text}}']);
        $this->_email->subject = strtr($this->_email->titleForOwner, $vars);
        $this->_email->altBody = "作者:" . $this->_comment->author . "\r\n链接:" . $this->_comment->permalink . "\r\n评论:\r\n" . $this->_comment->text;

        return $this;
    }

    /**
     * 访客邮件信息
     */
    public function guestMail()
    {
        $date = new \Typecho\Date($this->_comment->created);
        $vars = [
            '{{siteTitle}}' => $this->_options->title,
            '{{title}}'     => $this->_comment->title,
            '{{author_p}}'  => $this->_comment->originalAuthor,
            '{{author}}'    => $this->_comment->author,
            '{{permalink}}' => $this->_comment->permalink,
            '{{text}}'      => $this->_comment->text,
            '{{text_p}}'    => $this->_comment->originalText,
            '{{contactme}}' => $this->_comment->contactme,
            '{{time}}'      => $date->format('Y-m-d H:i:s'),
        ];

        $this->_email->msgHtml = $this->renderHtml($this->getTemplate('guest'), $vars, ['{{text}}', '{{text_p}}']);
        $this->_email->subject = strtr($this->_email->titleForGuest, $vars);
        $this->_email->altBody = "作者:" . $this->_comment->author . "\r\n链接:" . $this->_comment->permalink . "\r\n评论:\r\n" . $this->_comment->text;

        return $this;
    }

    /**
     * 渲染 HTML 模板：变量值先转义再替换，防止评论者写入的 HTML 注入到邮件正文
     *
     * @param string $template 模板内容
     * @param array $vars 占位符 => 原始值
     * @param array $multiline 需要保留换行的占位符（评论正文）
     * @return string
     */
    private function renderHtml(string $template, array $vars, array $multiline = []): string
    {
        foreach ($vars as $placeholder => $value) {
            $value = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $vars[$placeholder] = in_array($placeholder, $multiline) ? nl2br($value) : $value;
        }

        return strtr($template, $vars);
    }

    /**
     * 发送邮件
     *
     * @return bool|string|null
     */
    public function sendMail(): bool|string|NULL
    {
        /** 载入邮件组件 */
        $mailer = new PHPMailer();
        $mailer->CharSet = 'UTF-8';
        $mailer->Encoding = 'base64';
        $mailer->Timeout = 15; // 默认 300 秒，SMTP 卡住时会长时间占用 PHP 进程

        /** 选择发信模式 */
        switch ($this->_cfg->mode) {
            case 'mail':
                break;
            case 'sendmail':
                $mailer->IsSendmail();
                break;
            case 'smtp':
                $mailer->IsSMTP();
                if (in_array('validate', $this->_cfg->validate)) $mailer->SMTPAuth = true;

                if (in_array('ssl', $this->_cfg->validate)) {
                    $mailer->SMTPSecure = "ssl";
                } else if (in_array('tls', $this->_cfg->validate)) {
                    $mailer->SMTPSecure = "tls";
                }

                $mailer->Host     = $this->_cfg->host;
                $mailer->Port     = $this->_cfg->port;
                $mailer->Username = $this->_cfg->user;
                $mailer->Password = $this->_cfg->pass;
                break;
        }

        $mailer->SetFrom($this->_email->from, $this->_email->fromName);
        if (isset($this->_email->replyTo) && isset($this->_email->replyToName)) $mailer->AddReplyTo($this->_email->replyTo, $this->_email->replyToName);
        $mailer->Subject = $this->_email->subject;
        $mailer->AltBody = $this->_email->altBody;
        if (in_array('solve544', $this->_cfg->validate)) $mailer->AddCC($this->_email->from); // 躲避审查造成的 544 错误 

        $mailer->MsgHTML($this->_email->msgHtml);
        $mailer->AddAddress($this->_email->reciver, $this->_email->reciverName);
        // 默认校验 SMTP 证书，防止中间人截获账号密码；自签名证书的服务器需在设置中显式勾选跳过
        if (in_array('insecure', $this->_cfg->validate)) {
            $mailer->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
        }

        $result = $mailer->Send();
        if (!$result) $result = $mailer->ErrorInfo;

        $mailer->ClearAddresses();
        $mailer->ClearReplyTos();

        return $result;
    }

    /**
     * 获取邮件模板
     * 
     * @param string $type
     * @return string
     */
    public function getTemplate(string $template = 'owner'): string
    {
        $filename = $this->_template_dir  . $template . '.html';

        if (!file_exists($filename)) {
            throw new \Typecho\Widget\Exception('模板文件' . $template . '不存在', 404);
        }

        return file_get_contents($filename);
    }

    /**
     * 邮件发送测试
     */
    public function testMail()
    {
        if (self::widget('TypechoPlugin\CommentToMail\Console')->testMailForm()->validate()) {
            $this->response->goBack();
        }

        $email = $this->request->from('template', 'toName', 'to', 'title', 'content');
        $template = in_array($email['template'], ['owner', 'guest'], true) ? $email['template'] : null;

        if (!$template && (trim((string) $email['title']) === '' || trim((string) $email['content']) === '')) {
            $this->widget('\Widget\Notice')->set(_t('不使用模板时，邮件标题和内容不能为空'), 'error');
            $this->response->goBack();
        }

        $this->_email = new Email();

        $this->_email->from = $this->_cfg->user;
        $this->_email->fromName = $this->_cfg->fromName ? $this->_cfg->fromName : $this->_options->title;
        $this->_email->reciver = $email['to'] ? $email['to'] : $this->_user->mail;
        $this->_email->reciverName = $email['toName'] ? $email['toName'] : $this->_user->screenName;

        if ($template) {
            // 用示例评论走与真实通知相同的渲染流程
            $this->_comment = $this->sampleComment($template);
            $this->_email->titleForOwner = (string) $this->_cfg->titleForOwner;
            $this->_email->titleForGuest = (string) $this->_cfg->titleForGuest;
            $template === 'owner' ? $this->authorMail() : $this->guestMail();
            if (trim((string) $email['title']) !== '') $this->_email->subject = $email['title'];
        } else {
            $this->_email->subject = $email['title'];
            $this->_email->altBody = $email['content'];
            $this->_email->msgHtml = $email['content'];
        }

        $result = $this->sendMail();
        $sent = $result === true; // 失败时 sendMail 返回错误信息字符串，不能按真值判断

        /** 提示信息 */
        $this->widget('\Widget\Notice')->set(
            $sent ? _t('邮件发送成功') : _t('邮件发送失败: ') . $result,
            $sent ? 'success' : 'notice'
        );

        /** 转向原页 */
        $this->response->goBack();
    }

    /**
     * 测试邮件用的示例评论
     *
     * @param string $template owner|guest
     * @return Comment
     */
    private function sampleComment(string $template): Comment
    {
        $comment = new Comment();
        $comment->created = time();
        $comment->title = '示例文章';
        $comment->permalink = $this->_options->siteUrl;
        $comment->mail = 'visitor@example.com';
        $comment->ip = '127.0.0.1';
        $comment->status = 'waiting';
        $comment->text = "这是一条示例评论，用于预览邮件模板。\n第二行用来检查换行显示。";
        if ($template === 'guest') {
            // 访客回复场景：博主回复了访客的评论
            $comment->author = $this->_user->screenName;
            $comment->originalAuthor = '示例访客';
            $comment->originalText = "这是访客之前发表的示例评论。\n第二行用来检查换行显示。";
            $comment->text = '这是博主的示例回复。';
        } else {
            $comment->author = '示例访客';
        }
        $comment->contactme = $this->_cfg->contactme ?: $this->_user->mail;

        return $comment;
    }

    /**
     * 编辑模板文件
     * @param $file
     * @throws \Typecho\Widget\Exception
     */
    public function editTheme($file)
    {
        // 只允许编辑模板目录下已有的 .html 文件，防止 ../ 路径穿越写入任意文件
        $file = basename((string) $file);
        $path = $this->_template_dir . $file;

        if (preg_match('/^[_0-9a-z-]+\.html$/i', $file) && file_exists($path) && is_writeable($path)) {
            $content = (string) $this->request->content;
            // 先校验再写入：原实现先 fopen('wb') 清空文件，内容为空时再报“无法写入”，模板已被清空
            if (trim($content) === '') {
                $this->widget('Widget_Notice')->set(_t("模板内容不能为空，文件 %s 未修改", $file), 'error');
            } elseif (file_put_contents($path, $content) !== false) {
                $this->widget('Widget_Notice')->set(_t("文件 %s 的更改已经保存", $file), 'success');
            } else {
                $this->widget('Widget_Notice')->set(_t("文件 %s 无法被写入", $file), 'error');
            }
            $this->response->goBack();
        } else {
            throw new \Typecho\Widget\Exception(_t('您编辑的模板文件不存在'));
        }
    }
}
