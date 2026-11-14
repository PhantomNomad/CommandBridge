# Resources Unit

This is the Resources Unit's intake queue for the active incident: resources that have been ordered and are expected to arrive, ones that have arrived and been received, and ones that are closed.

Items arrive here from the [ICS 213RR](help:forms/ics213rr_v2/index). When Logistics completes a request and chooses **Track through Resources Unit**, an intake item is created for the receiving location Logistics picked, and is released for check-in automatically. The Resources Unit only needs to step in if something is wrong.

## The three lists

| List | What it shows |
|---|---|
| Resources Unit Intake Queue | Items expected to arrive: request number, status, category, quantity, kind and type, initial location. |
| Received / Active Resources | Items the Resources Unit has received. They stay here until they are moved on to the [ICS 211](help:forms/ics211/index) or [ICS 218](help:forms/ics218/index). |
| Closed / Resolved Intake Items | Items that are finished, returned or cancelled. |

## When a resource arrives

Open the item and, under **Record Resources Unit Receipt**, enter the quantity received, when and where it was received, who received it (name and position), the supplier, agency or owner, a contact and any notes. Press **Record Receipt in Resources Unit**.

Then put it on the right tracking form:

- **Add to ICS 211 Check-In List** for people, crews and teams.
- **Add to ICS 218 Equipment Inventory** for vehicles and equipment.

The page then shows which ICS form it is linked to.

## When something is wrong

Add a note under **Exception / review notes**, then use one of:

| Button | Use it when |
|---|---|
| Release for Check-in | A new item needs to be released to the check-in location by hand. |
| Mark No Tracking Required | It turns out to be something the Resources Unit doesn't track, such as supplies. |
| Return to Logistics | The order needs to be changed. The 213RR goes back to Logistics. |
| Cancel Intake | The resource is not coming. |
| Reopen | A closed item needs more work. |

Once a resource has been received or checked in, don't cancel it or mark it as not tracked. Release it through the ICS 211 or 218 and demobilization instead.

## Access

Who can see the queue, and who can record receipts and take action, is set in [Form permissions](help:admin/form_permissions) under the Resources Unit entry. It is normally the Resources Unit Leader and the Planning Section Chief. SysAdmins can always open it. The Planning Section Chief gets a dashboard notification when an item is released for check-in.
