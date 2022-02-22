---
name: Bug report
about: Create a report to help us improve
title: "[BUG]"
labels: "\U0001F41B bug"
assignees: ''

---

### Bug description
<!-- A clear and concise description of the bug. -->

### PHP version
<!-- Output of `php -v` -->

### Tracer version
<!-- Output of `php -r "echo phpversion('ddtrace').PHP_EOL;"` -->

### Installed extensions
<!-- Output of `php -m` -->

### OS info
<!-- Output of `cat /etc/os-release | grep -E "(NAME)|(VERSION)"` -->

### Diagnostics and configuration

#### Output of phpinfo() (ddtrace >= 0.47.0)
<!-- Remove this section if the installed version of ddtrace is < 0.47.0 -->

<!-- 1. Create a `phpinfo()` page: `<?php phpinfo(); ?>` and load the page from a web browser -->
<!-- 2. Scroll down to the "ddtrace" section -->
<!-- 2a. Take a screenshot of the whole "ddtrace" section and drag the image into this text box to attach the screenshot -->
<!-- 2b. OR copy the "DATADOG TRACER CONFIGURATION" JSON and the "Diagnostics" section and paste them here  -->

<!-- If this issue is related to the CLI SAPI, copy the output of `php --ri=ddtrace` and paste it here. -->

#### Output of dd-doctor (ddtrace < 0.47.0)
<!-- Remove this section if the installed version of ddtrace is >= 0.47.0 -->

<!-- 1. Deploy `dd-doctor.php` to your root folder `curl https://raw.githubusercontent.com/DataDog/dd-trace-php/master/src/dd-doctor.php -o <path-to-webroot>/<some-random-name>.php` -->
<!-- 2. Access it at `http://your-host/<some-random-name>.php` -->
<!-- 3. Paste the output here -->
<!-- 4. Remember to remove the file `<path-to-webroot>/<some-random-name>.php` when you are done -->

### Upgrading info
<!-- Remove this section if you did not upgrade ddtrace and/or PHP -->

<!-- If you are upgrading from a previous version of ddtrace and/or PHP, please provide the previously installed version number(s) here. -->
