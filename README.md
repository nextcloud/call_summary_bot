# Call Summary Bot

The call summary bot posts an overview message after the call listing all participants and outlining tasks

## Usage

- Any message starting with a Markdown todo `- [ ] ` or the keywords `task:` or `todo:` during a call will be recognized as a task
- You can also post multiple tasks in a single message, just put each on its own line starting with a keyword
- At the end of the call, the bot will summarize it and list all the attendees as well as the tasks in a markdown chat message

> [!NOTE]
> By default, only calls lasting at least 60 seconds are summarized. For easier quick testing this can be reduced to 0 seconds with the following command:
> ```shell
> sudo -u www-data php occ config:app:set 'call_summary_bot' 'min-length' --value '0'
> ```


![Screenshot showing a call summary chat message](docs/screenshot.png)

## Installation

Since this bot is written as a Nextcloud app, simply search for "Call summary bot" in the app list of your Nextcloud server, or download it manually from the [App store](https://apps.nextcloud.com/apps/call_summary_bot)
