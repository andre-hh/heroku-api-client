# Heroku API client

This is a PHP client for Heroku's API which is optimized for specific use cases:

* Getting a list of currently running dynos
* Running a one-off dyno
* Stopping a specific dyno
* Retrieving billing information

Failing API requests result in dedicated exceptions, which should be handle by the application using this client. 

Log messages are only implemented for debugging purposes; the log level of these log messages can be configured when
instantiating this client (it defaults to `info`). 
