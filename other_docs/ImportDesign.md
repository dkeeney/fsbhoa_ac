Import Design for the FSBHOA Access Control System  
The fields extracted from the property management system are the following:

- Property Address,
- First Name,
- Last Name,
- Second Owner First Name,
- Second Owner Last Name,
- Phone,
- Email
- Tenant Name(s)
- Tenent Email(s)
- Tenent Phone(s)

This spec describes the import module and how it moves data from the .csv import file into the database. This should be used not only for the initial load but also for periodic imports to sync the Photo ID database with the property management database.

The Official ground truth source of data is the property management database. It contains information about the properties, owners, and tenants but it does not contain a place to hold the RFID number, photos, the card status, issue & expiration dates which are needed to print the Photo ID’s and configure the controller to activate the RFID number to allow access on the amenity’s gates.

## Field descriptions

The .csv import file contains the following fields. The fields can be presented in any order and there may be other fields in the .csv which can be ignored.

Each row in the .csv contains the information on one property. It contains information for one or two owners and any number of tenants; each of which will be cardholders and therefore will get their own database record in the “ac_cardholder” table in the fsbhoa_db database.  
<br/>Property Address:

This is the street address of a property in the HOA community. It comes with the city/state/zip appended. Strip off the cit/state/zip by comparing it to text in the “Address Suffix to Remove” parameter and remove text that matches the suffix. This address will map to the “street_address” field of a record in the “ac_property” table in the fsbhoa_db. The index of that record will be placed in the “property_id” field of every cardholder record resulting from this row.

The ”street_address” field is split, at the first space, into “house_number” and “street_name” fields that are used for sorting.

First Name:

This is the first name of the first owner. This maps to “first_name” field in the database for the first owner’s record.

Last Name:

This is the last name of the first owner. This maps to” last_name” field in the database for the first owner’s record.

Second Owner First Name:

This is the first name of the second owner, if there is one. This maps to “first_name” field in the database for the second owner’s record.

Second Owner Last Name:

This is the last name of the second owner. This maps to “last_name” field in the database for the second owner’s record.

Phone:

This is a comma separated list of phone numbers associated with the owners. If there is one, it maps to the “phone“ field of the first owner’s record. If there is a second phone number, it maps to the “phone” field of the second owner’s record.

Email:

This is a comma separated list of email address associated with the owners. If there is one, it maps to the “email“ field of the first owner’s record. If there is a second email address, it maps to the “email” field of the second owner’s record.

Tenant Names(s):

This is a comma separated list of tenant names, if there is one. Parse the last word of each list item as the last name and the first word(s) as the first name. There may be several sets of first name/last name pairs in the list; each will map to the “first_name” and “last_name” fields on a separate record for each tenant.

Tenant Email(s):

This is a comma separated list of tenant email, if there is one. There may be several email addresses in the list; each will map to the “email” field on the record for each corresponding tenant. If there are more tenants than emails, leave the remaining fields blank. If there are too many, ignore the remainder.

Tenant Phone(s):

This is a comma separated list of tenant phone numbers, if there is one. There may be several phone numbers in the list; each will map to the “phone” field on the record for each corresponding tenant. If there are more tenants than phone numbers, leave the remaining fields blank. If there are too many, ignore the remainder. The phone numbers should all be normalized to a 10 digit number without punctuation. Remove the leading 1 if there are 11 digits.

##

Logic Flow:

For each row read from the .csv import file, parse the .csv fields as described above to create a set of ac_cardholder records in memory. Set the “resident_type” field for owners to “Resident Owner” and to “Tenant” for all tenants. Set the “origin” field to “import” on all cardholder records. Otherwise, use the database default values for all other fields. This will be the new list of cardholders.

Strip the suffix from the property address field, look this up in the ac_property table and obtain the property_id to be used on all cardholder records created for this row. Create a new ac_property table record if address is not found.

Read all ac_cardholder records from the database with a matching property_id and make a list of existing cardholders at that address.

Iterate the existing cardholder list and compare each to the new cardholder records. Match on a combination of First Name and Last Name. If there is no match and the existing records has an “origin” field value of “import”, move the existing cardholder record to the ac_deleted table, removing it from the ac_cardholder table. If there is a match and the. existing records has an “origin” field value of something other than “import”, discard the new record from the table.  
<br/>After the pass, if there are any tenant records left in either the existing list or the new list, then the owner is a “Landlord”. Set the resident_type field of the owner records in the new list as as “Landlord”.

Now, apply the new list to the existing records. If the new record does not exist in the database, add the record. If the new record already exists, update the matching record with following fields; “phone”, “email”, “resident_type”.


