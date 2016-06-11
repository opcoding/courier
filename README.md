OpCoding/Courier
================

This plugin eases continuous deployment from PHPCI

Installation
============

Courier is intended to be installed through composer:

```
require opcoding/courier
```
 
NOTE: at this time, the repository is private!

Usage
=====

Once installed, you just have to add such a section in your phpci.yml:

```
success:
    OpCoding\Courier\Courier:
        env-mapping:
          "master": production
          "*": development
        targets:
          production:
            www1:
              host: 192.168.56.101
              path: /var/www/showcase/
            www2:
              host: 192.168.56.102
```

