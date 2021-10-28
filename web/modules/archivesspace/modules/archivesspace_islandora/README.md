# ArchivesSpace to Islandora 8 Default Configurations

Islandora 8 provides a few fields that we can reuse instead of creating
ourselves:

  - Linked Agent
  - Member Of
  - Weight
  - Subject

This configuration module is setup to use these provided fields, unlike 
archivesspace_defaults which provides it's own versions. 

This module also provides RDF mappings for use with the JSON-LD module which
allows them to be indexed in Fedora and a Triple-store like other Islandora 8
content.

This module also adds a Source field to the islandora_object content type
allowing digital objects to link back to the archival_object they came from.
That stated, it is possible to use the archival object metadata records
*in place of* islandora_object, although it requires adjusting some of
the Context conditions and views to enable it.

Finally, no digital object migration is provided. Creating a migration of the 
digital object metadata could be done, creating the media and files as
Islandora is currently configured to structure them is not easy to do with 
the ArchivesSpace API endpoint for digital objects. 

Instead, it is recommended that repositories consider using the Drupal and
[Islandora migration tools](https://islandora.github.io/documentation/technical-documentation/migrate-csv/)
for loading data. Linking digital objects to archival objects is simple to do
using the archival object's reference identifier. For example, 

```yml
field_source: # The islandora_object 'Source' field we provide.
  -
    plugin: skip_on_empty # Ignore this field if no value is provided
    method: process
    source: ref_id # This would be a 'ref_id' column in an ingest CSV.
  -
    plugin: entity_lookup
    value_key: field_as_ref_id # This is the field where we store ref ids.
    bundle_key: type
    bundle: archival_object # We are looking for archival objects.
    entity_type: node
    ignore_case: true
```

Any unique field, not just the reference identifier can be used for a lookup.