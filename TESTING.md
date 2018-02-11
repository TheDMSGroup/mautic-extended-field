Testing Mautic Extended Field Plugin
===========

#### Test Dependencies

1. Install the Plugin. This should be automatic.

2. Create Custom Fields.

    Create 1 custom field for each data type using Object type Extended Field,
     and at least one data type for Extended Field Secure.
     
3. Assign data to test leads, adding values to each new Custom (Extended) Field.

4.  Build Lead Segments

    Create enough segments to include all new custom fields, with a varity
    of AND / OR groupings.
    
    Verify that Operator options apply for each data type chosen and
    all submit/save validations pass.
    
#### Items to Test

1. Run the app/console command to rebuild segemnts
   `mautic:segments:update`
   
   You should get a count of leads to be added OR removed from the list
   and then the add Lead or Remove lead function runs for each.
   
   This will repeat for each segment (list).
   
2. Remove data from Extended fields for some leads (so the lead no longer fits a segment criteria)
    Re-run the command from #1 above.
    
    Some leads should be removed from the list.
    
3. Trigger a Push Lead 
