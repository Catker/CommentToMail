## CommentToMail

> 一个Typecho异步邮件推送插件

适用版本: Typecho 1.2.0+ / PHP 8.0+（已在 Typecho 1.3.0 / PHP 8.1 上运行）

## 安装参考

1. clone或下载本项目
2. 重命名下载文件为 `CommentToMail`
3. 移动文件夹至 ~/usr/plugins/ 下
4. 后台启用插件, 配置SMTP等信息
5. 评论提交后自动发信：勾选「同步发送」时当场发送；否则在页面返回后由后台发送（需 PHP-FPM）
6. （可选）定时访问 `/action/comment-to-mail?do=deliverMail&key=你设置的key` 重试发送失败的邮件
7. 在控制台「邮件发送测试」中可选择模板，用示例评论发一封测试信预览效果

## Copyright

CommentToMail 作为一款老牌Typecho 邮件推送插件, 具有多个分支. 但大都长时间未更新, 且无法支持 php8 与 Typecho 1.2.0. 

本项目部分参考原项目 且对其进行大量重构.

邮件服务采用[PHPMailer](https://github.com/PHPMailer/PHPMailer)

本项目采用 GNU GENERAL PUBLIC LICENSE 开源
