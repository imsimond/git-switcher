== Git Switcher ==
Contributors: s1m0nd
Tags: git, developer, tools
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Git Switcher adds a button to the Admin Bar, letting you inspect and switch branches for git-enabled plugins.

== Description ==

Activate the plugin, and you will see a new Git Switcher button in your WordPress site's Admin Bar.

Click on it, and you will see a popover that:

- lists all git-enabled plugin folders in `wp-content/plugins`
- shows current branch per plugin folder
- expands each plugin to list local branches
- switches branches directly from the UI
- provides a Settings tab for configuring git binary path

== Requirements ==

- WordPress with admin bar enabled
- User with `manage_options` capability
- Local development environment where PHP can execute git commands

== Settings ==

Use the popover `Settings` tab to set git binary path (optional):

- `/usr/bin/git`
- `/opt/homebrew/bin/git`
- `/usr/local/bin/git`

If not set, Git Switcher attempts these common paths automatically.

== Testing ==

Click the link below to spin up a WordPress Playground instance, and see Git Switcher in action. Note: Playground does not include a git binary, so you can preview most of the UI, but not all of the functionality.

https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/imsimond/git-switcher/refs/heads/main/blueprint.json

==  Changelog ==

This plugin is being developed at GitHub. See the [repository's commit history](https://github.com/imsimond/git-switcher/commits/main/) for the latest changes.