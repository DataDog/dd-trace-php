---
name: Bug report
about: Create a report to help us improve
title: "[BUG]"
labels: "\U0001F41B bug"
assignees: ''

---

**Describe the bug**
A clear and concise description of what the bug is.

**PHP Info**
- Output of `php -v`
- Output of `php -m`

**OS Info**
- Output of `cat /etc/os-release | grep -E "(NAME)|(VERSION)"`

**Output of dd-doctor**
- Deploy `dd-doctor` to your root folder `curl https://raw.githubusercontent.com/DataDog/dd-trace-php/master/src/dd-doctor.php -o <path-to-webroot>/<some-random-name>.php`
- Access it at `http://your-host/<some-random-name>.php`
- Paste here the output
- Remember to remove the file `<path-to-webroot>/<some-random-name>.php` when you are done.

**If you are upgrading**

If you are upgrading from a previous version that did not show the bug, please provide us with the version number.
