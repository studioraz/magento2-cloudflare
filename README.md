```
Template Repository for Magento 2 repository module

To create a new repository based on this repository choose it on the "Create a new repository" page's Repository template drop-down. 

1. Replace template variables {ModuleName}, {title}, {description}, {version} {module-name} with real values.
3. Update README content below
4. Remove this whole section 
```

# Studio Raz Magento 2 for {title}

## Features
1. ...
2. ...

## Installation

1. To access Studio Raz *private* packages in Composer, configure authentication for the project:
    ```
    composer config --auth http-basic.repo.packagist.com <username> <password>
    ```
2. Set up the custom repository with the following command
    ```
    composer config repositories.private-packagist composer https://repo.packagist.com/studioraz/
    composer config repositories.packagist.org false
    ```

3. Install this module within Magento 2 using composer
    ```
    composer require studioraz/magento2-cloudflare
    ```

4. After this, enable the module as usual
    ```
    bin/magento mo:e SR_Cloudflare &&
    bin/magento s:up
    ```
