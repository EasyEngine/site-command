# EasyEngine/site-command

Performs basic site functions in easyengine.

`site` command contains following subcommand
 * [create](#ee-site-create)
 * [delete](#ee-site-delete)
 * [disable](#ee-site-disable)
 * [enable](#ee-site-enable)
 * [info](#ee-site-info)
 * [list](#ee-site-list)
 * [start](#ee-site-start)
 * [stop](#ee-site-stop)
 * [restart](#ee-site-restart)
 * [reload](#ee-site-reload)

## create
Runs the site creation.

```bash
ee site create example.com                      # install wordpress without any page caching (default)
ee site create example.com --wp                 # install wordpress without any page caching
ee site create example.com --wpredis            # install wordpress with page caching
ee site create example.com --wpsubir            # install wpmu-subdirectory without any page caching
ee site create example.com --wpsubir --wpredis  # install wpmu-subdirectory with page caching
ee site create example.com --wpsubdom           # install wpmu-subdomain without any page caching
ee site create example.com --wpsubdom --wpredis # install wpmu-subdomain with page cache
```

Let's Encrypt SSL
```bash
# Enable SSL using Let’s Encrypt (You can add --letsencrypt along with any other flag.)
ee site create example.com [--letsencrypt|--le]
ee site create example.com --le                 # install wordpress without any page caching + letsencrypt ssl
ee site create example.com --wpredis --le       # install wordpress with page caching + letsencrypt ssl
ee site create example.com --wpsubdom --le      # install wordpress wpmu-subdomain + wildcard letsencrypt ssl
```

## delete
Deletes an existing EasyEngine site including the webroot and the database.

```bash
ee site delete example.com          # Asks for confirmation.
ee site delete example.com --yes    # Skips the confirmation prompt.
```

## disable
Disables a website. It will stop all containers which will free up resources used by this site. The site's data stored in the disk will still be safe.

```bash
ee site disable example.com
```

## enable
Enables a website. It will start the docker containers of the website if they are stopped.

```bash
ee site enable example.com
```

## info
Display all the relevant site information, credentials and useful links.

```bash
ee site info example.com
```

## list
Lists the created websites.

```bash
ee site list                                           # Lists all sites (default: tabular format) 
ee site list --format=[count|csv|json|table|text|yaml] # Lists all sites in a particular format
ee site list --enabled                                 # List enabled sites 
ee site list --disabled                                # List disabled sites 
```

## start
Starts services associated with site.

```bash
ee site start example.com		# Defaults to all services
ee site start example.com --nginx
```

## stop
Stops services associated with site.

```bash
ee site stop example.com		# Defaults to all services
ee site stop example.com --mailhog
```

## restart
Restarts containers associated with site. This action will have a few seconds of downtime.

```bash
ee site restart example.com		# Defaults to all services
ee site restart example.com --nginx
```

## reload
Reload services in containers without restarting container(s) associated with site.

```bash
ee site reload example.com		# Defaults to all services
ee site reload example.com --nginx
```

