## ArchivesSpace-to-Drupal Integration

This module, archivesspace (lower-case), is a series of Drupal modules for harvesting data from an ArchivesSpace (mixed-case) instance for public display. Note, this module may also be referred to as "AS→D" for convenience to disambiguate it from the ArchivesSpace software. It extends the Drupal core Migrate API with plugins, provides sensible defaults for displaying ArchivesSpace resources in Drupal and all of the usual Drupal content and website management tools. AS→D closely tracks and is designed to be used with [Islandora 8](https://github.com/Islandora/islandora/tree/8.x-1.x).

A few possible use cases include:
- Using Drupal Views to build glossary displays, user-configurable sorting,
- Optionally deploying with Search API with Apache SOLR to provide a configurable and granular control over search results
- Building custom reports, logs, and user analytics
- Developing customized "Omeka-like" online exhibitions of archival material
- Researcher annotations, user-administered "favorites", save-for-later carts
- Managing research requests
- Mapping archival records

Changes can be made in real time or asynchronously in batches during cron run. Once ArchivesSpace records are consumed by Drupal, they can be further enriched with NISO-defined metadata, published in a customizable discovery theme layer, and published in web service formats for consumption by other resources.

## Pre-history
The original ArchivesSpace/Drupal Integration project is a began in 2014 as a [Kress Foundation-funded](http://www.kressfoundation.org) suite of Drupal 7 modules to support cataloging and discovery at the [American Academy in Rome](http://dhc.aarome.org). The project extends several Drupal modules including [RESTClient](https://www.drupal.org/project/restclient), [Web Service Data](https://www.drupal.org/project/wsdata), and custom code to request ArchivesSpace JSON objects and pipe them into Drupal entities. These entities can then be formatted, manipulated, and indexed via traditional Drupal methods (Views, Solr, Elastic Search, etc.). The original project is monitored and updated for maintenance fixes only. No new features will be considered or added.

Instead, this project provides an entirely new suite of Drupal modules for Drupal 8 and beyond that adhere to PSR coding standards and module development best practices in order to release this suite to both ArchivesSpace and Drupal developer communities.

## Quick Start Guide
1. Install the module
    - Install via composer (`composer require drupal/archivesspace`) OR
    - Install manually
        1. Ensure all the dependencies are installed.
        2. Save the module to your Drupal site's modules directory.
2. Configure [title_length](https://www.drupal.org/project/title_length). Drupal's titles aren't very long while ArchivesSpace display strings can be much longer. See details in the "Drupal Title Field" section below, but to get started quickly for testing, add `$settings['node_title_length_chars'] = 16365;` to your settings.php file before enabling the title_length module. 
3. Enable the appropriate submodule for your site (i.e. either with Islandora or without). E.g. `drush en -y archivesspace_defaults` if not using Islandora OR `drush en -y archivesspace_islandora_defaults` to use with Islandora. _Note: you may uninstall the sub-module after enabling it and the configurations will remain; just leave the base archivesspace module enabled._
4. Configure your ArchivesSpace API endpoint, username, and password on the configration page `/admin/archivesspace/config`. (These can be set using drush and the [state-set](https://drushcommands.com/drush-8x/state/state-set/) command for `archivesspace.base_uri`, `archivesspace.username`, and `archivesspace.password`.)
5. Run the migrations. E.g. `drush mim --group=archivesspace_agents,archivesspace_subjects,archivesspace`. _Note: most of the migrations will run relatively quickly, within a few minutes. However top_containers and archival objects can take a very long time to process for large sites (more than an hour)._

## Components

### Building the Drupal data model
The content types cover:
- Repositories
- Resources
- Archival Objects
- Digital Objects (if using the configurations provided by the archivesspace_defaults submodule)

Vocabularies for subjects and agents are provided by the [Controlled Access Terms module](https://github.com/Islandora/controlled_access_terms).

### Migrations
This module provides migrations for all the supported content types and vocabularies.

By default, resources or archival objects that do not have the "publish" flag set are not migrated, nor do archival objects with an unpublished ancestor. However, once a resource or archival object is migrated it will remain in Drupal even if the flag is changed in ArchivesSpace unless manually removed.

Also note that the module will replace `<emph>` tags in titles with `<em>` and other EAD-specific tags in notes with their HTML 5 corollaries. However, Drupal title fields (analogous to the ArchivesSpace display strings) do not support HTML tags. We therefore recommend installing the [HTML Title](https://www.drupal.org/project/html_title) module and configuring it to use the `<em>` tag although it is not required for installing and using this module.

### Batch Updates
Although the Migrate API supports updating previously migrated content, on very large sites the provided update mechanism requires significantly more RAM than normal operation.

To improve scalability this module provides a batch-based update mechanism that can be triggered either by using the form @ `/admin/archivesspace/batch-update` or by using the Drush command `archivesspace:update`. Both include options for specifying the number of pages to process (10 items per page) and a timestamp for when it should look for updates. The module will also track the last update timestamp it updates to avoid repeatedly updating the same records unnecessarily. We recommend running the drush command via your system's cron on a regular basis.

> Note: It is possible to adjust your agent and subject migrations so that it only pulls in those referenced by published archival descriptions. However, linking an agent or subject to a descriptive record **does not** update the linked agent or subject so that it will not be available when the descriptive resource is updated during the batch update. If a site chooses to use this method, they should run the agent and subject migrations (e.g. `drush mim --group=archivesspace_agents,archivesspace_subjects --update`) **before** the batch update. There is still a very small chance it will be missed if an existing published agent/subject's first link to a published descriptive record is made *after* the agent/subject import update completes and *before* the descriptive record update occurs. Running the update in the off-hours avoids this (unlikely) problem.

### Purging Deleted Items

ArchivesSpace does not include deleted items in the query used to discover updated content, reasonably so. Deleted content is discovered via a separate API and so this module provides a corresponding form (`/admin/archivesspace/purge-deleted`) and drush command (`archivesspace:purge`) to perform periodic purges of content deleted from ArchivesSpace. Both of these options allow a site to specify how many pages of deletes to process at once. However, it is much more efficient than an update process and could run over the whole delete feed regularly without issue.

### Authentication
To migrate content the module must be configured with a valid ArchivesSpace API point, username, and password. The migrations assume the API point of 'localhost:8089', 'admin' user, and 'admin' password unless configured. Visit the page `/admin/configuration/archivesspace` in your Drupal site to configure these settings.

## Drupal Title Field

As mentioned in the quickstart, Drupal titles are quite short by default compared to the ArchivesSpace resource title and archival object display_label lengths. The [title_length module](https://www.drupal.org/project/title_length) allows us to increase the title field's length, but even that module's default larger size (500) is still too small for archival object display_labels. The largest possible title field length on Drupal is contrained by the total length of all the fields on the node_field_data table. On a standard Drupal 9 install using MySQL the largest possible title length is 16,365 (the value used in the quickstart).

However, most ArchivesSpace instances won't have resource title and display_string lengths that come close to the maximum length the title_length module can support. Further, using the maximum length is inefficient for the database space usage and performance. To find a more reasonable value run the following queries on the ArchivesSpace database to find the current length requirement: `SELECT MAX(LENGTH(display_string)) FROM archival_object; SELECT MAX(LENGTH(title)) FROM resource;`. We recommend taking the larger value and then adding more characters for a buffer.

## License
This ArchivesSpace integration module is licensed on the same terms as Drupal, under GPLv2 or later.

[About Drupal licensing](https://www.drupal.org/about/licensing)

The AS2d8 license also covers its related modules, hacks, plugins, and configuration management.
