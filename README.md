# DiskQuota Module for Omeka S

![Screenshot of the module](https://github.com/ateeducacion/omeka-s-DiskQuota/blob/main/.github/assets/screenshot1.png)

This module allows administrators to set disk quota limits for users and sites in Omeka S. It prevents users from uploading files that would exceed the quota set for a user or a site.

## Features

- Set a maximum storage limit (quota) per user and per site
- Track current storage usage of each user and site
- Prevent uploads that would exceed the user's or site's quota
- Display quota information in the admin panel
- Provide a dedicated section for managing quotas


## Installation

### Manual Installation

1. Download the latest release from the GitHub repository
2. Extract the zip file to your Omeka S `modules` directory
3. Log in to the Omeka S admin panel and navigate to Modules
5. Click "Install" next to DiskQuota

### Using Docker

A Docker Compose file is provided for easy testing:

1. Make sure you have Docker and Docker Compose installed
2. Clone this repository
3. From the repository root, run:

```bash
make up
```

4. Wait for the containers to start (this may take a minute)
5. Access Omeka S at http://localhost:8080
6. Finish the installation and login as admin user
7. Navigate to Modules and install the DiskQuota module

## Installation

See general end user documentation for [Installing a module](http://omeka.org/s/docs/user-manual/modules/#installing-modules)

## Usage

1. Once installed, navigate to the admin panel
2. To set a user quota:
   - Go to Users section
   - Select a user and click on the "User Settings" tab
   - Set the desired quota size in megabytes (MB)
3. To set a site quota:
   - Navigate to any site's admin panel
   - Click on the "Site admin" tab in the left sidebar
   - Set the desired quota size in megabytes (MB)
4. To set unlimited quota for either users or sites, enter 0

The module will automatically track usage and prevent uploads that would exceed the quota.

## Requirements

- Omeka S 4.x or later

## License

This module is published under the [GNU GPLv3](LICENSE) license.
