<?php
class export {
    public $biller_id;
    public $customer_id;
    public $domain_id;
    public $end_date;
    public $file_name;
    public $file_type;
    public $format;
    public $id;
    public $module;
    public $start_date;

    private $download;

    public function __construct() {
        $this->domain_id = domain_id::get();
        $this->download = false;
    }

    public function setDownload($download) {
        $this->download = $download;
    }

    public function showData($data) {
        if ($this->file_name == '' && $this->module == 'payment') {
            $this->file_name = 'payment' . $this->id;
        }

        // @formatter:off
        switch ($this->format) {
            case "print":
                echo ($data);
                break;

            case "pdf":
                pdfThis($data, $this->file_name, $this->download);
                if ($this->download) exit();
                break;

            case "file":
                $invoice    = Invoice::getInvoice($this->id);
                $preference = Preferences::getPreference($invoice['preference_id'], $this->domain_id);

                // xls/doc export no longer uses the export template $template = "export";
                header("Content-type: application/octet-stream");

                // header("Content-type: application/x-msdownload");
                switch ($this->module) {
                    case "statement":
                        header('Content-Disposition: attachment; filename="statement.' .
                               addslashes($this->file_type) . '"');
                        break;

                    case "payment":
                        header('Content-Disposition: attachment; filename="payment' .
                               addslashes($this->id . '.' . $this->file_type) . '"');
                        break;

                    default:
                        header('Content-Disposition: attachment; filename="' .
                               addslashes($preference['pref_inv_heading'] . $this->id . '.' .
                               $this->file_type) . '"');
                        break;
                }

                header("Pragma: no-cache");
                header("Expires: 0");
                echo ($data);
                break;
        }
        // @formatter:on
    }

    public function getData() {
        global $pdoDb, $smarty, $siUrl, $show_only_unpaid;

        // @formatter:off
        switch ($this->module) {
            case "statement":
                $havings = new Havings();
                if ($this->filter_by_date == "yes" &&
                    isset($this->start_date) &&
                    isset($this->end_date)) {
                        $havings->addHavings(Invoice::buildHavings("date_between", array($this->start_date, $this->end_date)));
                }

                if ($show_only_unpaid == "yes") {
                    $havings->addHavings(Invoice::buildHavings("money_owed"));
                }
                $pdoDb->setHavings($havings);

                $pdoDb->addSimpleWhere("b.id", $this->biller, "AND");
                $pdoDb->addSimpleWhere("c.id", $this->customer_id, "AND");

                $invoices  = Invoice::select_all("count", "", "", null, "", "", "");

                $statement = array("total" => 0, "owing" => 0, "paid" => 0);
                foreach ($invoices as $row) {
                    $statement['total'] += $row['invoice_total'];
                    $statement['owing'] += $row['owing'];
                    $statement['paid']  += $row['INV_PAID'];
                }

                $templatePath     = "./templates/default/statement/index.tpl";
                $biller_details   = Biller::select($this->biller_id);
                $billers          = $biller_details;
                $customer_details = Customer::get($this->customer_id);
                $this->file_name  = "statement_" .
                                    $this->biller_id     . "_" .
                                    $this->customer_id   . "_" .
                                    $this->start_date . "_" .
                                    $this->end_date;

                $smarty->assign('biller_id'       , $billers['id']);
                $smarty->assign('biller_details'  , $biller_details);
                $smarty->assign('billers'         , $billers);
                $smarty->assign('customer_id'     , $this->customer_id);
                $smarty->assign('customer_details', $customer_details);
                $smarty->assign('show_only_unpaid', $show_only_unpaid);
                $smarty->assign('filter_by_date'  , $this->filter_by_date);
                $smarty->assign('invoices'        , $invoices);
                $smarty->assign('start_date'      , $this->start_date);
                $smarty->assign('end_date'        , $this->end_date);
                $smarty->assign('statement'       , $statement);

                $data = $smarty->fetch("." . $templatePath);
                break;

            case "payment":
                $payment = Payment::select($this->id);

                // Get Invoice preference to link from this screen back to the invoice
                $invoice = Invoice::getInvoice($payment['ac_inv_id']);
                $biller  = Biller::select($payment['biller_id']);

                $logo = getLogo($biller);
                $logo = str_replace(" ", "%20", $logo);

                $customer          = Customer::get($payment['customer_id']);
                $invoiceType       = Invoice::getInvoiceType($invoice['type_id']);
                $customFieldLabels = getCustomFieldLabels($this->domain_id, true);
                $paymentType       = PaymentType::select($payment['ac_payment_type']);
                $preference        = Preferences::getPreference($invoice['preference_id'], $this->domain_id);

                $smarty->assign("payment"          , $payment);
                $smarty->assign("invoice"          , $invoice);
                $smarty->assign("biller"           , $biller);
                $smarty->assign("logo"             , $logo);
                $smarty->assign("customer"         , $customer);
                $smarty->assign("invoiceType"      , $invoiceType);
                $smarty->assign("paymentType"      , $paymentType);
                $smarty->assign("preference"       , $preference);
                $smarty->assign("customFieldLabels", $customFieldLabels);
                $smarty->assign('pageActive'       , 'payment');
                $smarty->assign('active_tab'       , '#money');

                $css = $siUrl . "/templates/invoices/default/style.css";
                $smarty->assign('css', $css);

                $templatePath = "./templates/default/payments/print.tpl";
                $data = $smarty->fetch("." . $templatePath);
                break;

            case "invoice":
                $invoice = Invoice::select($this->id);

                $invoice_number_of_taxes = Invoice::numberOfTaxesForInvoice($this->id, $this->domain_id);

                $customer = Customer::get($invoice['customer_id']);

                $past_due_date = (date("Y-m-d", strtotime('-30 days')) . ' 00:00:00');
                $past_due_amt  = CustomersPastDue::getCustomerPastDue($invoice['customer_id'], $this->id, $past_due_date);

                $biller     = Biller::select($invoice['biller_id']);
                $preference = Preferences::getPreference($invoice['preference_id'], $this->domain_id);
                $defaults   = getSystemDefaults($this->domain_id);

                $logo = getLogo($biller);
                $logo = str_replace(" ", "%20", $logo);

                $invoiceItems    = Invoice::getInvoiceItems($this->id);
                $spc2us_pref     = str_replace(" ", "_", $invoice['index_name']);
                $this->file_name = $spc2us_pref;

                $customFieldLabels = getCustomFieldLabels($this->domain_id, true);

                // Set the template to the default
                $template = $defaults['template'];

                $templatePath  = "./templates/invoices/${template}/template.tpl";
                $template_path = "../templates/invoices/${template}";
                $pluginsdir    = "./templates/invoices/${template}/plugins/";
                $css           = $siUrl . "/templates/invoices/${template}/style.css";

                $smarty->plugins_dir = $pluginsdir;

                $pageActive = "invoices";
                $smarty->assign('pageActive', $pageActive);
                if (file_exists($templatePath)) {
                    $this->assignTemplateLanguage($preference);

                    $smarty->assign('biller'                 , $biller);
                    $smarty->assign('customer'               , $customer);
                    $smarty->assign('invoice'                , $invoice);
                    $smarty->assign('invoice_number_of_taxes', $invoice_number_of_taxes);
                    $smarty->assign('preference'             , $preference);
                    $smarty->assign('logo'                   , $logo);
                    $smarty->assign('template'               , $template);
                    $smarty->assign('invoiceItems'           , $invoiceItems);
                    $smarty->assign('template_path'          , $template_path);
                    $smarty->assign('css'                    , $css);
                    $smarty->assign('customFieldLabels'      , $customFieldLabels);
                    $smarty->assign('past_due_amt'           , $past_due_amt);
                    $data = $smarty->fetch("." . $templatePath);
                }

                break;
        }
        // @formatter:on

        return $data;
    }

    public function execute() {
        $this->showData($this->getData());
    }

    // assign the language and set the locale from the preference
    public function assignTemplateLanguage($preference) {
        // get and assign the language file from the preference table
        $pref_language = $preference['language'];
        if (isset($pref_language)) {
            $LANG = getLanguageArray($pref_language);
            if (isset($LANG) && is_array($LANG) && count($LANG) > 0) {
                global $smarty;
                $smarty->assign('LANG', $LANG);
            }
        }

        // Overide config's locale with the one assigned from the preference table
        $pref_locale = $preference['locale'];
        if (isset($pref_language) && strlen($pref_locale) > 4) {
            global $config;
            $config->local->locale = $pref_locale;
        }
    }
}
