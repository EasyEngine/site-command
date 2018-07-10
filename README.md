# EasyEngine/site-command

Performs basic site functions in easyengine.

`site` command contains following subcommand
 * [ee site create](#ee-site-create)
 * [ee site delete](#ee-site-delete)
 * [ee site disable](#ee-site-disable)
 * [ee site enable](#ee-site-enable)
 * [ee site info](#ee-site-info)
 * [ee site list](#ee-site-list)
 * [ee site start](#ee-site-start)
 * [ee site stop](#ee-site-stop)
 * [ee site restart](#ee-site-restart)
 * [ee site reload](#ee-site-reload)

#### ee site create
Runs the site creation.

```bash
ee site create example.com                      # install wordpress without any page caching (default)
ee site create example.com --wp                 # install wordpress without any page caching
ee site create example.com --wpredis            # install wordpress with page caching
ee site create example.com --wpsubir            # install wpmu-subdirectory without any page caching
ee site create example.com --wpsubir --wpredis  # install wpmu-subdirectory with page caching
ee site create example.com --wpsubdom           # install wpmu-subdomain without any page caching
ee site create example.com --wpsubdom --wpredis # install wpmu-subdomain with page cache

# Enable SSL using Let’s Encrypt (You can add --letsencrypt along with any other flag.)
ee site create example.com --letsencrypt
```

#### ee site delete
Deletes an existing EasyEngine site including the webroot and the database.

```bash
ee site delete example.com          # Asks for confirmation.
ee site delete example.com --yes    # Skips the confirmation prompt.
```

#### ee site disable
Disables a website. It will stop all containers which will free up resources used by this site. The site's data stored in the disk will still be safe.

```bash
ee site disable example.com
```

#### ee site enable
Enables a website. It will start the docker containers of the website if they are stopped.

```bash
ee site enable example.com
```

#### ee site info
Display all the relevant site information, credentials and useful links.

```bash
ee site info example.com
```

#### ee site list
Lists the created websites.

```bash
ee site list                                           # Lists all sites (default: tabular format) 
ee site list --format=[count|csv|json|table|text|yaml] # Lists all sites in a particular format
ee site list --enabled                                 # List enabled sites 
ee site list --disabled                                # List disabled sites 
```

#### ee site start
Starts services associated with site.

```bash
ee site start example.com		# Defaults to all services
ee site start example.com --nginx
```

#### ee site stop
Stops services associated with site.

```bash
ee site stop example.com		# Defaults to all services
ee site stop example.com --mailhog
```

#### ee site restart
Restarts containers associated with site. This action will have a few seconds of downtime.

```bash
ee site restart example.com		# Defaults to all services
ee site restart example.com --nginx
```

#### ee site reload
Reload services in containers without restarting container(s) associated with site.

```bash
ee site reload example.com		# Defaults to all services
ee site reload example.com --nginx
```

