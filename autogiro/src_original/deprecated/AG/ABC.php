<?php
namespace iio\swegiro\AG;

/**
 * 'A' => "Medgivandeunderlag",
 * 'B' => "Betalningsunderlag",
 * 'C' => "Makuleringar/ändringar av betalningsunderlag",
 *
 * Each AgLayoutABC object only works with one BGC customer number and
 * one BG account number. If you try to parse AG files intended for
 * other customer numbers or BG account numbers an error will be raised.
 *
 * <h3>Creating files</h3>
 *
 * A file sent to BGC may contain independent sections with A, B and C posts.
 * When mixing in one single file you must call addSection() before
 * changing. It is recommended not to have multiple sections with the
 * same layut, however no error will be raised if you do so.
 *
 * <code>$toBgc = new AgLayoutABC('123456', '12345678');
 * $toBgc->addSection();
 * $toBgc->removeConsent('8507021601');
 * $toBgc->addConsent('8203230258', '1111', '9999999999', '198203230258');
 * $toBgc->addSection();
 * $toBgc->cancelBetNr('12837');
 * $toBgc->cancelBetNrDate('12837', '20110323');
 * $toBgc->cancelTransaction('12837', '20110323', 223.45, "reference");
 * $toBgc->modifyAllNewDate('20110456');
 * $toBgc->modifyDateNewDate('20091234', '20110456');
 * $toBgc->modifyBetNrDate('1234', '20091234', '20110456');
 * $toBgc->modifyTransaction('1234', '20091234', '20110456', 123.45, "referens");
 * $toBgc->addSection();
 * $toBgc->addInvoice('8203230258', 123.45, '20110323', '999999');
 * $toBgc->addCredit('8203230258', 123.45, '20110323', '999999');
 * if ( $txt = $toBgc->getFile() ) {
 *     print_r($txt);
 * } else {
 *     print_r($toBgc->getErrors());
 * }</code>
 */
class ABC extends Object
{
    protected $struct = "/^((01(0[3-5])+)|(01([83]2)+)|(01(2[3-9])+))+$/";

    protected $map = array(
        '01' => array("/^01(\d{8})AUTOGIRO.{44}(\d{6})(\d{10})\s*$/", 'parseHead'),
        // consents
        '03' => array("/^03(\d{10})(\d{16})\s*$/", 'parseRemoveConsent'),
        '04' => array("/^04(\d{10})(\d{16})(.{0,4})(.{0,12})(.{0,12}).{0,20}(.{0,2})\s*$/", 'parseAddConsent'),
        '05' => array("/^05(\d{10})(\d{16})\d{10}(\d{16})\s*$/", 'parseChangeBetNr'),
        // transactions
        '82' => array("/^([83]2)(.{8})(.)(.{3}).(\d{16})(\d{12})(\d{10})(.{0,16}).{0,10}.?\s*$/", 'parseTransaction'),
        '32' => array("/^([83]2)(.{8})(.)(.{3}).(\d{16})(\d{12})(\d{10})(.{0,16}).{0,10}.?\s*$/", 'parseTransaction'),
        // cancellations
        '23' => array("/^23(\d{10})(\d{16})\s*$/", 'parseCancelBetNr'),
        '24' => array("/^24(\d{10})(\d{16})(\d{8})\s*$/", 'parseCancelBetNrDate'),
        '25' => array("/^25(\d{10})(\d{16})(\d{8})(\d{12})(\d\d).{0,8}(.{0,16})\s*$/", 'parseCancelTransaction'),
        // modifications
        '26' => array("/^(2[6-9])(\d{10})(.{16})(.{8})(.{12})(..)(\d{8})(.{0,16})\s*$/", 'parseModify'),
        '27' => array("/^(2[6-9])(\d{10})(.{16})(.{8})(.{12})(..)(\d{8})(.{0,16})\s*$/", 'parseModify'),
        '28' => array("/^(2[6-9])(\d{10})(.{16})(.{8})(.{12})(..)(\d{8})(.{0,16})\s*$/", 'parseModify'),
        '29' => array("/^(2[6-9])(\d{10})(.{16})(.{8})(.{12})(..)(\d{8})(.{0,16})\s*$/", 'parseModify'),
    );

    /**
     * Write section on parsing complete
     */
    protected function parsingComplete()
    {
        if ( $this->count() != 0 ) {
            $stack = $this->getStack();
            $tc = array_key_exists('tc', $stack[0]) ? $stack[0]['tc'] : null;
            if ( $tc==3 || $tc==4 || $tc==5 ) {
                $layout = "A";
            } elseif ( $tc>22 && $tc<30 ) {
                $layout = "C";
            } else {
                $layout = "B";
            }
            $this->writeSection($layout);
        }
    }

    protected function parseHead($date, $customerNr, $bg)
    {
        $this->parsingComplete();
        if ( !$this->validBg($bg) ) return false;
        if ( !$this->validCustomerNr($customerNr) ) return false;
        $this->setDate($date);
        return true;
    }


    /* consents */

    protected function parseRemoveConsent($bg, $betNr)
    {
        if ( !$this->validBg($bg) ) return false;
        $consent = array(
            'tc' => 3,
            'name' => $this->tcNames[3],
            'betNr' => ltrim($betNr, '0'),
        );
        $this->push($consent);
        return true;
    }

    protected function parseAddConsent(
        $bg,
        $betNr,
        $clearing = false,
        $account = false,
        $orgNr = false,
        $reject = false
    ) {
        if ( !$this->validBg($bg) ) return false;
        $betNr = ltrim($betNr, '0');


        $consent = array(
            'tc' => 4,
            'name' => $this->tcNames[4],
            'betNr' => $betNr,
            'account' => self::buildAccountNr($betNr, $clearing, $account),
            'reject' => ( preg_match("/^\s*$/", $reject) ) ? false : true,
        );
        
        if ( $orgNr ) {
            self::buildStateIdNr($orgNr, $orgNrType);
            $consent[$orgNrType] = $orgNr;
        }
        
        $this->push($consent);
        return true;
    }

    protected function parseChangeBetNr($bg, $oldBetNr, $newBetNr)
    {
        if ( !$this->validBg($bg) ) return false;
        $consent = array(
            'tc' => 5,
            'name' => $this->tcNames[5],
            'oldBetNr' => ltrim($oldBetNr, '0'),
            'newBetNr' => ltrim($newBetNr, '0'),
        );
        $this->push($consent);

        return true;
    }


    /* cancellations */

    protected function parseCancelBetNr($bg, $betNr)
    {
        if ( !$this->validBg($bg) ) return false;
        $cancel = array(
            'tc' => 23,
            'name' => $this->tcNames[23],
            'betNr' => ltrim($betNr, '0'),
        );
        $this->push($cancel);

        return true;
    }

    protected function parseCancelBetNrDate($bg, $betNr, $date){
        if ( !$this->validBg($bg) ) return false;
        $cancel = array(
            'tc' => 24,
            'name' => $this->tcNames[24],
            'betNr' => ltrim($betNr, '0'),
            'date' => $date,
        );
        $this->push($cancel);
        return true;
    }

    protected function parseCancelTransaction($bg, $betNr, $date, $amount, $code, $ref = "")
    {
        if ( !$this->validBg($bg) ) return false;
        $cancel = array(
            'tc' => 25,
            'name' => $this->tcNames[25],
            'transType' => ( $code=='82' ) ? 'I' : 'C',            
            'betNr' => ltrim($betNr, '0'),
            'date' => $date,
            'amount' => $this->str2amount($amount),
            'ref' => trim($ref),
        );
        $this->push($cancel);
        return true;
    }


    /* modifications */

    protected function parseModify($tc, $bg, $betNr, $oldDate, $amount, $code, $newDate, $ref = "")
    {
        if ( !$this->validBg($bg) ) return false;
        $mod = array(
            'tc' => (int)$tc,
            'name' => $this->tcNames[(int)$tc],        
        );
        if ( !preg_match("/^\s*$/", $betNr) ) $mod['betNr'] = ltrim($betNr, '0');
        if ( !preg_match("/^\s*$/", $oldDate) ) $mod['oldDate'] = $oldDate;
        if ( !preg_match("/^\s*$/", $amount) ) $mod['amount'] = $this->str2amount($amount);
        $mod['newDate'] = $newDate;
        if ( !preg_match("/^\s*$/", $ref) ) $mod['ref'] = $ref;
        if ( $tc == '29' ) {
            $mod['transType'] = ( $code=='82' ) ? 'I' : 'C';            
        }
        $this->push($mod);
        return true;
    }


    /* FUNCTIONS TO CREATE FILES */

    /**
     * Add a new section post (TC = 01)
     */
    public function addSection()
    {
        $date = date('Ymd');
        $AG = str_pad("AUTOGIRO", 52);
        $customerNr = str_pad($this->getValue('customerNr'), 6, '0', STR_PAD_LEFT);
        $bg = $this->getValue('bg');

        // addLine() är borttagen, ska skapa XML istället
        $this->addLine("01$date$AG$customerNr$bg");
    }


    /* consents */

    /**
     * Write consent post for regular accout number to file.
     * @param string $betNr Number to identify AG consent. Max 16 numbers.
     * @param string $clearing Account clearing number. 4 characters.
     * @param string $account
     * @param string $orgNr Swedish social security number, or organisation number
     * @param bool $reject Set to true if this is an answer to an online application
     * and you DECLINE the application. If in doubt, do not use.
     */
    public function addConsent($betNr, $clearing, $account, $orgNr, $reject = false)
    {
        $betNr = str_pad($betNr, 16, '0', STR_PAD_LEFT);
        if ( strlen($clearing) != 4 ) {
            $this->error(_("Clearing number must be 4 characters long"), 'addConsent()');
            return false;
        }
        $account = str_pad($account, 12, '0', STR_PAD_LEFT);
        $blank = str_pad("", 20);
        $reject = ($reject) ? "AV" : "  ";
        $bg = $this->getValue('bg');

        // addLine() är borttagen, ska skapa XML istället
        $this->addLine("04$bg$betNr$clearing$account$orgNr$blank$reject");
    }

    /**
     * Write remove consent post to file
     * @param string $betNr Number to identify AG consent. Max 16 numbers.
     */
    public function removeConsent($betNr)
    {
        $betNr = str_pad($betNr, 16, '0', STR_PAD_LEFT);
        $bg = $this->getValue('bg');

        // addLine() är borttagen, ska skapa XML istället
        $this->addLine("03$bg$betNr");
    }


    /* transactions */

    /**
     * Write transaction post from $betNr to your BG account.
     *
     * <code>
     * $period values:
     * 0 = once
     * 1 = once every month, on $date
     * 2 = once every three months, on $date
     * 3 = once every six months, on $date
     * 4 = once every year, on $date
     * 5 = once every month, on the laste banking day
     * 6 = once every three months, on the laste banking day
     * 7 = once every six months, on the laste banking day
     * 8 = once every year, on the laste banking day
     * </code>
     *
     * @param string $betNr Number to identify AG consent. Max 16 numbers.
     * @param string|int|float $amount Divide units and cents with a dot (.).
     * @param string $date YYYYMMDD
     * @param string $ref
     * @param int $period Se table
     * @param int $repetitions Leve empty for $period==0 or no repetition limit
     */
    public function addInvoice($betNr, $amount, $date, $ref = "", $period = 0, $repetitions = "   ")
    {
        return $this->addTransaction('82', $betNr, $amount, $date, $ref, $period, $repetitions);
    }

    /**
     * Write transaction post from your BG account to $betNr
     * @param string $betNr Number to identify AG consent. Max 16 numbers.
     * @param string|int|float $amount Divide units and cents with a dot (.).
     * @param string $date YYYYMMDD
     * @param string $ref
     * @param int $period Se table in addInvoice() documentation.
     * @param int $repetitions Leve empty for $period==0 or no repetition limit
     */
    public function addCredit($betNr, $amount, $date, $ref = "", $period = 0, $repetitions = "   ")
    {
        return $this->addTransaction('32', $betNr, $amount, $date, $ref, $period, $repetitions);
    }

    /**
     * Internal function to support addInvoice() and addCredit().
     * @param string $tc Transaction code 82 (invoice) or 32 (credit).
     * @param string $betNr Number to identify AG consent. Max 16 numbers.
     * @param string|int|float $amount Divide units and cents with a dot (.).
     * @param string $date YYYYMMDD
     * @param string $ref
     * @param int $period Se table in addInvoice() documentation.
     * @param int $repetitions Leve empty for $period==0 or no repetition limit
     */
    private function addTransaction($tc, $betNr, $amount, $date, $ref = "", $period = 0, $repetitions = "   ")
    {
        $date = str_pad($date, 8);
        $betNr = str_pad($betNr, 16, '0', STR_PAD_LEFT);
        $amount = $this->amount2str($amount);
        $amount = str_pad($amount, 12, '0', STR_PAD_LEFT);
        $ref = str_pad($ref, 16);
        $bg = $this->getValue('bg');

        // addLine() är borttagen, ska skapa XML istället
        $this->addLine("$tc$date$period$repetitions $betNr$amount$bg$ref");
    }


    /* cancellations */

    /**
     * Cancel all transactions involving $betNr
     * @param string $betNr Number to identify AG consent. Max 16 numbers.
     */
    public function cancelBetNr($betNr)
    {
        return $this->cancel($betNr);
    }

    /**
     * Cancel all transactions involving $betNr on $date
     * @param string $betNr Number to identify AG consent. Max 16 numbers.
     * @param string $date YYYYMMDD
     */
    public function cancelBetNrDate($betNr, $date)
    {
        return $this->cancel($betNr, $date);
    }

    /**
     * Cancel one transaction
     * @param string $betNr Number to identify AG consent. Max 16 numbers.
     * @param string $date YYYYMMDD
     * @param string|int|float $amount
     * @param string $ref
     * @param bool $credit true of transaction is a credit, false invoice.
     */
    public function cancelTransaction($betNr, $date, $amount, $ref = false, $credit = false)
    {
        return $this->cancel($betNr, $date, $amount, $ref, $credit);
    }

    /**
     * Internal function to support cancelBetNr(), cancelBetNrDate() and cancelTransaction()
     * @param string $betNr Number to identify AG consent. Max 16 numbers.
     * @param string $date YYYYMMDD
     * @param string|int|float $amount
     * @param string $ref
     * @param bool $credit true of transaction is a credit, false invoice.
     */
    private function cancel($betNr, $date = false, $amount = false, $ref = false, $credit = false)
    {
        $betNr = str_pad($betNr, 16, '0', STR_PAD_LEFT);
        $bg = $this->getValue('bg');
        if ( !$date ) {
            //Only betNr, cancel all for this betNr

            // addLine() är borttagen, ska skapa XML istället
            $this->addLine("23$bg$betNr");
        } elseif ( !$amount ) {
            //Cancel all for thir betNr and date

            // addLine() är borttagen, ska skapa XML istället
            $this->addLine("24$bg$betNr$date");
        } else {
            //Cancel one transaction
            $amount = $this->amount2str($amount);
            $amount = str_pad($amount, 12, '0', STR_PAD_LEFT);
            $code = ( $credit ) ? '32' : '82';
            $ref = str_pad($ref, 16);

            // addLine() är borttagen, ska skapa XML istället
            $this->addLine("25$bg$betNr$date$amount$code        $ref");
        }
    }


    /* modifications */

    /**
     * Set new date for all transactions (use with caution).
     * @param string $newDate YYYYMMDD
     */
    public function modifyAllNewDate($newDate)
    {
        return $this->modify("26", $newDate);
    }

    /**
     * Set new date for all transaction on $oldDate (use with caution).
     * @param string $oldDate YYYYMMDD
     * @param string $newDate YYYYMMDD
     */
    public function modifyDateNewDate($oldDate, $newDate)
    {
        return $this->modify("27", $newDate, $oldDate);
    }

    /**
     * Set new date for all transactions involving $betNr on $oldDate
     * @param string $betNr Number to identify AG consent. Max 16 numbers.
     * @param string $oldDate YYYYMMDD
     * @param string $newDate YYYYMMDD
     */
    public function modifyBetNrDate($betNr, $oldDate, $newDate)
    {
        $betNr = str_pad($betNr, 16, '0', STR_PAD_LEFT);
        return $this->modify("28", $newDate, $oldDate, $betNr);
    }

    /**
     * Set new date for a transaction
     * @param string $betNr Number to identify AG consent. Max 16 numbers.
     * @param string $oldDate YYYYMMDD
     * @param string $newDate YYYYMMDD
     * @param string|int|float $amount
     * @param string $ref
     * @param bool $credit true of transaction is a credit, false invoice.
     */
    public function modifyTransaction($betNr, $oldDate, $newDate, $amount, $ref, $credit=false)
    {
        $betNr = str_pad($betNr, 16, '0', STR_PAD_LEFT);
        $amount = $this->amount2str($amount);
        $amount = str_pad($amount, 12, '0', STR_PAD_LEFT);
        $code = ( $credit ) ? '32' : '82';
        return $this->modify("29", $newDate, $oldDate, $betNr, $amount, $code, $ref);
    }

    /**
     * Interal function to support modifyAllNewDate(), modifyDateNewDate(),
     * modifyBetNrDate() and modifyTransaction().
     * @param string $tc
     * @param string $newDate YYYYMMDD
     * @param string $oldDate YYYYMMDD
     * @param string $betNr Number to identify AG consent. Max 16 numbers.
     * @param string|int|float $amount
     * @param string $code
     * @param string $ref
     */
    private function modify($tc, $newDate, $oldDate="        ", $betNr="                ", $amount="            ", $code="  ", $ref="")
    {
        $bg = $this->getValue('bg');

        // addLine() är borttagen, ska skapa XML istället
        $this->addLine("$tc$bg$betNr$oldDate$amount$code$newDate$ref");
    }


    /* DATESETNAMN */

    /**
     * Get datesetname for contents.
     * @param string $comm Way of communicationg with BGC. 'BGCM' for BgCom,
     * 'BGLK' for BG Link, 'AGZZ' or 'TEST' for test, 'AGAG' for others (or leave empty).
     */
    public function getDatesetname($comm="AGAG")
    {
        if ( strtoupper($comm) == 'TEST' ) $comm = "AGZZ";
        $customerNr = $this->getValue('customerNr');
        return "BFEP.I$comm.K0$customerNr";
    }

    /**
     * Write contents to filesystem
     * @param string $comm See getDatesetname()
     * @param string $dirname
     * @return int Bytes written, false on failure.
     */
    public function writeFile($comm="AGAG", $dirname=false)
    {
        $fname = $this->getDatesetname($comm);
        return parent::writeFile($fname, $dirname);
    }
}
