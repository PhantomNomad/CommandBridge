# Finance/Admin 213RR queue

This page is where the Finance/Administration Section reviews the cost side of resource requests ([ICS 213RR](help:forms/ics213rr_v2/index)). By default it lists every 213RR for the active incident, including ones already approved, paid, returned, rejected or not needing Finance.

Finance/Admin is notified when Logistics completes an order. Finance review runs alongside delivery: it does not hold up receiving or check-in.

## The list

Each row shows the request number, the kind and type requested, and three separate statuses:

| Column | Meaning |
|---|---|
| Finance Status | Finance/Admin's decision on the request. |
| Resource Status | Where the resource is in Resources Unit tracking and check-in. |
| Workflow | Which step of the 213RR process the request is at. |

It also shows the estimated and actual cost, vendor and invoice number. Open **Detail** to work on one request.

## Recording a Finance decision

On the request, enter the **Actual Amount**, **Vendor**, **Invoice #** and **Cost Code** as you have them, choose a **Finance Action**, add notes and press **Save Finance/Admin Update**.

| Finance Action | What it does |
|---|---|
| Approve | Records Finance/Admin sign-off. The request stays approved. |
| Mark paid | Records sign-off and marks the item paid. |
| Return for revision | Sends the request back to be fixed. Say why in the notes. |
| Place on hold | Leaves it in Finance review without approving it. |
| Reject / cancel | Cancels the request. Say why in the notes. |
| Finance not required | Records that Finance/Admin has nothing to do on this item. |

Every decision is recorded on the request's activity timeline and in the [audit log](help:audit/index).

## Access

Who can open the queue, and who can record decisions, is set in [Form permissions](help:admin/form_permissions). It is normally the Finance/Administration Section. People with view access only see the message "You have view-only access to Finance/Admin" and no update buttons.
