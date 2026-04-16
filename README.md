# Git Switcher

Git Switcher adds a **Git Switcher** button to the WordPress admin bar for administrators.

It opens a WordPress Components popover that:

- discovers git-enabled plugin folders in `wp-content/plugins`
- shows current branch per plugin folder
- expands each plugin to list local branches
- switches branches directly from the UI
- provides a Settings tab for configuring git binary path

## Requirements

- WordPress with admin bar enabled
- User with `manage_options` capability
- Local development environment where PHP can execute git commands

## Settings

Use the popover **Settings** tab to set git binary path (optional):

- `/usr/bin/git`
- `/opt/homebrew/bin/git`
- `/usr/local/bin/git`

If not set, Git Switcher attempts these common paths automatically.

## Notes

- This plugin is intended for local development workflows.
- Branch switching runs `git checkout` in selected plugin folders.
- UI uses `@wordpress/components` `Popover` and `TabPanel`.
