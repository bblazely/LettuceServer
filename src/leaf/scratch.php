<?php

Class scratch_controller extends LettuceController {
    /** @var  scratch_model model */
    protected $model;

    public function root() {
        print "<pre>";
var_dump($_SERVER);

        $text = "1234.4567";
        list($a, $b) = explode('.', $text);
        print $a." ".$b;


        $text = "1234";
        list($c, $d) = explode('.', $text);
        print $c." ".$d;
        die();

        print Common::getLocaleCollator()->getLocale(Locale::ACTUAL_LOCALE);

        $test_limit = 500;
        for ($i = 0; $i < $test_limit; $i++) {
            $a = [
                $this->generateRandomString(5),
                rand(0,100),
                $this->generateRandomString(100)
            ];
            $test[] = $a;
        }

        $coll = Common::getLocaleCollator();


        $test_b = $test;
        $start = microtime(true);
        $index = 0;
        $result = usort($test_b, function ($a, $b) use ($coll, $index) {
            return $coll->compare($a[$index], $b[$index]);
        });
        $end = microtime(true) - $start;
        print "\nusort Result: ".$end."\n";


        $start = microtime(true);
        $test_sort = [];
        for ($i =0; $i < $test_limit; $i++) {
            $test_sort[] = $test[$i][0];
        }
        $coll->asort($test_sort);
        $test_output = [];
        foreach ($test_sort as $key => $data) {
            $test_output[] = $test[$key];
        }
        $end = microtime(true) - $start;
        print "bounce Result: ".$end."\n";


        $start = microtime(true);
// Leader !
        $test_sort = [];
        for ($i =0; $i < $test_limit; $i++) {
            $test_sort[] = $test[$i][0];
        }
        $coll->asort($test_sort, Collator::NUMERIC_COLLATION);
        $test_output = [];
        foreach ($test_sort as $key => $data) {
            $test_output[] = $test[$key];
        }

        $end = microtime(true) - $start;
        print "bounce Result: ".$end."\n";

    }


    function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

}

Class scratch_model extends LettuceModel {
    public function test_assoc() {
        return $this->entity()->get(4295124341)->assoc()->getCount(301);
    }
}

