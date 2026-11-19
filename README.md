<p align="center"><a href="https://asgaros.com" target="_blank" rel="noopener noreferrer"><img src="admin/images/logo.png" alt="Asgaros Forum"></a></p>

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/asgaros-forum)](https://wordpress.org/plugins/asgaros-forum/)
[![WordPress Plugin Rating](https://img.shields.io/wordpress/plugin/rating/asgaros-forum)](https://wordpress.org/plugins/asgaros-forum/)
[![WordPress Plugin Active Installs](https://img.shields.io/wordpress/plugin/installs/asgaros-forum)](https://wordpress.org/plugins/asgaros-forum/)
[![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dy/asgaros-forum)](https://wordpress.org/plugins/asgaros-forum/)
[![WordPress Plugin: Tested WP Version](https://img.shields.io/wordpress/plugin/tested/asgaros-forum)](https://wordpress.org/plugins/asgaros-forum/)
[![GitHub issues](https://img.shields.io/github/issues-raw/Asgaros/asgaros-forum)](https://github.com/Asgaros/asgaros-forum/issues)
[![GitHub pull requests](https://img.shields.io/github/issues-pr-raw/Asgaros/asgaros-forum)](https://github.com/Asgaros/asgaros-forum/pulls)

Asgaros Forum is a lightweight and simple plugin to extend WordPress with the function of a forum.

## Description
Asgaros Forum is the perfect WordPress plugin if you want to extend your website with a lightweight and feature-rich discussion board. It is easy to set up, super fast and perfectly integrated into WordPress.

### Support, Demo & Documentation
* [Support & Demo](https://asgaros.com/support/)
* [Documentation](https://asgaros.com/docs/)
* [Available Hooks](hooks.md)

### Features
* Simple Content Management
* Profiles & Members List
* Notifications & Feeds
* Powerful Editor
* SEO-friendly
* Reactions
* Uploads
* Search
* Polls
* Widgets
* Statistics
* Guest Postings
* Approval, Banning & Reporting
* Moderators, Permissions & Usergroups
* Customizable Responsive Theme
* Multilingualism
* Multiple Instances
* Multisite Compatibility
* myCRED Integration

## Installation
* Download `Asgaros Forum`
* Activate the plugin via the `Plugins` screen in WordPress
* A new forum-page is automatically created during the installation
* You can also add a forum to a page manually by adding the `[forum]` shortcode to it
* Add this page to your menu so your users can access your forum
* On the left side of the administration area you will find a new menu called `Forum` where you can change the settings and create new categories & forums
* Thats all!

## License
Asgaros Forum is [GPLv2 licensed](LICENSE)

## APDA fork development

Use Node 22+, then `npm ci` and `npm run dev`. Open http://127.0.0.1:9400/?page_id=2.
WordPress Playground runs PHP 8.3 and WordPress 6.8.3 with a disposable private SQLite
site. Local login: **admin / password**. Ctrl-C stops it; restarting resets sample data.
No Docker, system PHP, or production credentials required. See
[the task skill](.agents/skills/forum-task/SKILL.md) for tests and PR workflow.
For browser screenshots run `npx playwright install chromium` once.
