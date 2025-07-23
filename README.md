# centreon-nagvis-integration
Tested on centreon 24.10 and nagvis 1.9.47
supports objects (hosts, services, servicegroups, hostgroups) on static maps as well as automaps and geomaps

Changes made:
* replaced the deprecated mysql extension with msqli
* modified getDirectChildNamesByHostName, getDirectParentNamesByHostName, getHostAckByHost, parseFilter, getHostNamesInHostgroup, and getProgramStart to correct filtering for automap to work properly

How you can link centreon and nagvis:
1. Add GlobalBackendcentreonbroker.php to nagvis/share/server/core/classes/
2. Configure nagvis/etc/nagvis.ini.php:
[default]
backend = centreonbroker   

[backend_centreonbroker]
backendtype     = "centreonbroker"
dbhost          = "localhost"
dbport          = 3306
dbname          = "centreon_storage"
dbuser          = "centreon"
dbpass          = "yourpassword"
dbinstancename  = "default"
htmlcgi         = "/centreon"
