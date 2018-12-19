# Mautic Extended Field [![Latest Stable Version](https://poser.pugx.org/thedmsgroup/mautic-extended-field-bundle/v/stable)](https://packagist.org/packages/thedmsgroup/mautic-extended-field-bundle) [![License](https://poser.pugx.org/thedmsgroup/mautic-extended-field-bundle/license)](https://packagist.org/packages/thedmsgroup/mautic-extended-field-bundle) [![Build Status](https://travis-ci.com/TheDMSGroup/mautic-extended-field.svg?branch=master)](https://travis-ci.com/TheDMSGroup/mautic-extended-field)

![](Assets/img/icon.png?raw=true)

Features:
* Allows for hundreds of custom fields.
* Prevents outages due to field additions/deletions at large scale (when used exclusively).
* Allows for separation of data for those with HIPAA/PCI concerns.

Out of the box Mautic has a few challenges for companies that need hundreds of fields, or to store millions of leads.
A full problem definition can be found at https://github.com/mautic/mautic/issues/4139. 
This plugin is a compromise between a strict EAV approach and current custom field support, and allows an admin to disable the core "Contact" fields in favor of Extended fields if needed.

![](Assets/img/screenshot.png?raw=true)

## Plugin Overview

#### New Schema Structure

When creating a custom field, rather than adding a column to either the Lead (leads) or Company (companies) tables,
This plugin implements new tables based on field type (boolean, text, choice, etc), and similar tables
for secure data fields. A total of 14 new tables are created following the table name syntax:

	 `leads_fields_leads . [DATA TYPE] . xref`   OR
	 `leads_fields_lead . [DATA TYPE] . _secure_xref`

Each table contains the columns:

	 `lead_id` - the id of the lead from the `leads` table the row applies to
	 `lead_field_id` - the id of the corresponding Custom Field from the `lead_fields` table
	 `value` - the value for the Custom Field. 

#### Overriding Mautic Lead Bundle

This plugin takes two approaches to overriding current methods for managing custom fields.

To allow for new Object types for Custom Fields, three form types are overridden
using Symfony's Form Inheritance. By declaring a Form Inheritance tag in the plugin's config
the `build` method of the original Form Type can be overridden to allow this plugin to redefine
the form, injecting two new validated Object Types - `Extended Field` and `Secure Extended Field`.
This Plugin overrides the `FieldType`, `LeadType` and `ListType` form definitions.

The other method of overriding the Mautic Lead Bundle is Symfony's Compiler Pass method. 
This allows the plugin to redirect the Classes used for service definitions from the Mautic Lead Bundle
to use Classes defined by this plugin.
The following services were redefined using this method:

    `mautic.lead.model.field`
    `mautic.form.type.leadfield`
    `mautic.lead.model.lead`
    `mautic.lead.repository.lead`
    `mautic.lead.model.list`
    
## Installation & Usage

Choose a release that matches your version of Mautic.

| Mautic version | Installation                                                        |
| -------------- | ------------------------------------------------------------------- |
| 2.12.x         | `composer require thedmsgroup/mautic-extended-field-bundle "^2.12"` |
| 2.14.x         | `composer require thedmsgroup/mautic-extended-field-bundle "^2.14"` |
| 2.15.x         | `composer require thedmsgroup/mautic-extended-field-bundle "^2.15"` |

1. Install by running the command above or by downloading the appropriate version and unpacking the contents into a folder named `/plugins/MauticExtendedFieldBundle`
2. Go to `/s/plugins/reload`. The Extended Fields plugin should show up. Installation is complete.
3. When creating new custom fields `/s/contacts/fields/new` select Object "Extended" or "Extended Secure".
4. Optionally disable "core" lead fields in ExtendedField Settings `/s/config/edit`.

## TODO

- Permissions for Secure fields
-- Need to implement Permission pass methods for any ExtendedFieldSecure data type display, edit or retrieval.
- Support retrieving leads by unique IDs that are also extended fields.
-- Override LeadRepository::getLeadIdsByUniqueFields to join and pivot on columns.
- Support segmentation by an "empty" extended field (outer join).

# Changes for Report Compatibility

    ** NOTE ** The ReportSubscriber event listener dynamically checks for an edit or
    view request of reports and dynanically sets priority of the REPORT_ON_BUILD.
    The REPORT_ON_BUILD and REPORT_ON_GENERATE subscribers exist specifically to add
    UTM Tags into the Segment Membership Data Source. This is tangental functionality to the core "Extended Fields" purpose
    and will hopefully go away pending approval of a Core modification that adds UTM Tags into the contacts column list.
    

Icon by [lakshishasri](https://thenounproject.com/lakshishasri/) from the Noun project.
