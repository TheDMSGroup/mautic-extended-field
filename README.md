# Mautic Extended Field [![Latest Stable Version](https://poser.pugx.org/thedmsgroup/mautic-extended-field-bundle/v/stable)](https://packagist.org/packages/thedmsgroup/mautic-extended-field-bundle) [![License](https://poser.pugx.org/thedmsgroup/mautic-extended-field-bundle/license)](https://packagist.org/packages/thedmsgroup/mautic-extended-field-bundle) [![Build Status](https://travis-ci.org/TheDMSGroup/mautic-extended-field.svg?branch=master)](https://travis-ci.org/TheDMSGroup/mautic-extended-field)

![](Assets/img/icon.png?raw=true)

Allows near-infinite custom fields.

This plugin was created to help overcome an anticipated challenge supporting Custom Fields. 
A full problem definition can be found at https://github.com/mautic/mautic/issues/4139. 
This plugin is a compromise between a strict EAV approach and current custom field support, and allows an admin to disable the core "Contact" fields in favor of Extended fields when needed.

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

#### Overriding Mutic Lead Bundle
This plugin takes two approaches to overriding current methods for managing custom fields.

To allow for new Object types for Custom Fields, three form types are overridden
using Symfony's Form Inheritance. By declaring a Form Inheritance tag in the plugin's config
the `build` method of the original Form Type can be overridden to allow this plugin to redefine
the form, injecting two new validated Object Types - `Extended Field` and `Secure Extended Field`.
This Plugin overrides the `FieldType`, `LeadType` and `ListType` form definitions.

The other method of overriding the Mautic Lead Bundle is Symfony's Compiler Pass method. 
This allows the plugin to redirect the Classes used for service definitions from the Mautic Lead Bundle
to use Classes defined by this plugin.
The following services were redefined using this methid:

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

1. Install by running the command above or by downloading the appropriate version and unpacking the contents into a folder named `/plugins/MauticExtendedFieldBundle`
2. Go to `/s/plugins/reload`. The Extended Fields plugin should show up. Installation is complete.
3. When creating new custom fields `/s/contacts/fields/new` select Object "Extended" or "Extended Secure".

## TODO

- Permissions for Secure fields
-- Need to implement Permission pass methods for any ExtendedFieldSecure data type display, edit or retrieval.
- Support retrieving leads by unique IDs that are also extended fields.
-- Override LeadRepository::getLeadIdsByUniqueFields to join and pivot on columns.

# Review and refactor for 2.14.x

Compiler passes to refactor:
- ExtendedFieldModel - done.
- OverrideLeadModel - done.
- OverrideLeadRepository - done
- OverrideListModel - done

Internal overrides to refactor:
- ExtendedFieldRepositoryTrait - done
- EntityExtendedFieldsBuildFormTrait - done (could use more refactoring)
- OverrideLeadFieldRepository - done
- LeadTypeExtension - done

Errors/Notices to be resolved:
- Saving a new extended field: 
-- Undefined index: no in /Users/hd/mautic-eb/mautic/app/bundles/LeadBundle/Model/ListModel.php on line 756
-- Undefined index: yes in /Users/hd/mautic-eb/mautic/app/bundles/LeadBundle/Model/ListModel.php on line 757