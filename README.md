# export-users-report
Util script to export Kaltura registered users as an excel report

## Setting up and running this util
1. `git clone https://github.com/kaltura-vpaas/export-users-report.git` 
2. `cd export-users-report` 
3. `cp config.php.template config.php`
4. Edit `config.php` and add your Kaltura account IDs and API Admin Secrets (from [KMC>Integration Settings](https://kmc.kaltura.com/index.php/kmcng/settings/integrationSettings)), and the email and smtp settings accordingly
5. `composer install`
6. `php get-users.php`

## Where to get help
* Join the [Kaltura Community Forums](https://forum.kaltura.org/) to ask questions or start discussions
* Read the [Code of conduct](https://forum.kaltura.org/faq) and be patient and respectful

## Get in touch
You can learn more about Kaltura and start a free trial at: http://corp.kaltura.com    
Contact us via Twitter [@Kaltura](https://twitter.com/Kaltura) or email: community@kaltura.com  
We'd love to hear from you!

## License and Copyright Information
All code in this project is released under the [AGPLv3 license](http://www.gnu.org/licenses/agpl-3.0.html) unless a different license for a particular library is specified in the applicable library path.   

Copyright © Kaltura Inc. All rights reserved.
