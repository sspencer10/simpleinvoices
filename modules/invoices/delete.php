<?php
/*
 *  Script: delete.php
 *      Do the deletion of an invoice page
 *
 *  Authors:
 *      Justin Kelly, Nicolas Ruflin
 *
 *  Last edited:
 *      2016-09-27
 *
 *  License:
 *      GPL v3 or above
 *
 *  Website:
 *      http://www.simpleinvoices.org
 */
global $smarty;

// stop the direct browsing to this file - let index.php handle which files get displayed
checkLogin();

// get the invoice id
$invoice_id = $_GET['id'];
$invoice = Invoice::getInvoice($invoice_id);
$preference = getPreference($invoice['preference_id']);
$defaults = getSystemDefaults();
$invoicePaid = calc_invoice_paid($invoice_id);

$invoiceobj = new Invoice();
$invoiceItems = $invoiceobj->getInvoiceItems($invoice_id);

$smarty->assign("invoice", $invoice);
$smarty->assign("preference", $preference);
$smarty->assign("defaults", $defaults);
$smarty->assign("invoicePaid", $invoicePaid);
$smarty->assign("invoiceItems", $invoiceItems);

/* If delete is disabled - dont allow people to view this page */
if ($defaults['delete'] == 'N') {
    die('Invoice deletion has been disabled, you are not supposed to be here');
}

if (($_GET['stage'] == 2) && ($_POST['doDelete'] == 'y')) {
    global $dbh;

    $dbh->beginTransaction();
    $error = false;

    // delete line item taxes
    $invoiceobj = new Invoice();
    $invoice_line_items = $invoiceobj->getInvoiceItems($invoice_id);

    foreach($invoice_line_items as $key => $value) {
        if ($value) {} // elimiates unused warning
        delete('invoice_item_tax', 'invoice_item_id', $invoice_line_items[$key]['id']);
    }

    // Start by deleting the line items
    if (!delete('invoice_items', 'invoice_id', $invoice_id)) {
        $error = true;
    }

    // delete products from products table for total style
    if ($invoice['type_id'] == 1) {
        if ($error || !delete('products', 'id', $invoiceItems['0']['product']['id'])) {
            $error = true;
        }
    }

    // delete the info from the invoice table
    if ($error || !delete('invoices', 'id', $invoice_id)) {
        $error = true;
    }
    if ($error) {
        $dbh->rollBack();
    } else {
        $dbh->commit();
    }
    // TODO - what about the stuff in the products table for the total style invoices?
    echo "<meta http-equiv='refresh' content='2;URL=index.php?module=invoices&view=manage' />";
}

$smarty->assign('pageActive', 'invoice');
$smarty->assign('active_tab', '#money');
