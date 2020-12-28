<?php

class RedisCache {

    public $isEnabled;
    public $clearCache = 0;
    public $language = 'en';
    public $Page = array();
    public $Row = 1;
    public $Part = 0;
    public $MemKey = "";
    public $Duration = 0;
    public $cacheReadTimes = 0;
    public $cacheWriteTimes = 0;
    public $keyHits = array();
    public $languageFolderArray = array();

    /** @var Redis */
    public $redis;

    function __construct() {
        $success = $this->createRedisClient(); // Connect to Redis
        if ($success) {
            $this->isEnabled = 1;
        } else {
            $this->isEnabled = 0;
        }
    }

    private function createRedisClient()
    {
        global $BASIC;
        $redis = new Redis();
        $params = [
            $BASIC['redis_host'],
        ];
        if (!empty($BASIC['redis_port'])) {
            $params[] = $BASIC['redis_port'];
        }
        if (!empty($BASIC['redis_timeout'])) {
            $params[] = $BASIC['redis_timeout'];
        }
        $connectResult = $redis->connect(...$params);
        $auth = [];
        if (!empty($BASIC['redis_password'])) {
            $auth['pass'] = $BASIC['redis_password'];
            if (!empty($BASIC['redis_username'])) {
                $auth['user'] = $BASIC['redis_username'];
            }
            $connectResult = $connectResult && $redis->auth($auth);
        }
        if ($connectResult) {
            if (is_numeric($BASIC['redis_database'])) {
                $selectDatabaseResult = $redis->select($BASIC['redis_database']);
                if (!$selectDatabaseResult) {
                    $msg = "select redis database: {$BASIC['redis_database']} fail";
                    write_log($msg);
                    throw new \RuntimeException($msg);
                }
            }
            $this->redis = $redis;
        } else {
            write_log(sprintf('connect to redis with params: %s , with auth: %s fail', json_encode($params), json_encode($auth)));
        }
        return $connectResult;
    }

    function getIsEnabled() {
        return $this->isEnabled;
    }

    function setClearCache($isEnabled) {
        $this->clearCache = $isEnabled;
    }

    function getLanguageFolderArray() {
        return $this->languageFolderArray;
    }

    function setLanguageFolderArray($languageFolderArray) {
        $this->languageFolderArray = $languageFolderArray;
    }

    function getClearCache() {
        return $this->clearCache;
    }

    function setLanguage($language) {
        $this->language = $language;
    }

    function getLanguage() {
        return $this->language;
    }

    function new_page($MemKey = '', $Duration = 3600, $Lang = true) {
        if ($Lang) {
            $language = $this->getLanguage();
            $this->MemKey = $language."_".$MemKey;
        } else {
            $this->MemKey = $MemKey;
        }
        $this->Duration = $Duration;
        $this->Row = 1;
        $this->Part = 0;
        $this->Page = array();
    }

    function set_key(){

    }

    //---------- Adding functions ----------//

    function add_row(){
        $this->Part = 0;
        $this->Page[$this->Row] = array();
    }

    function end_row(){
        $this->Row++;
    }

    function add_part(){
        ob_start();
    }

    function end_part(){
        $this->Page[$this->Row][$this->Part]=ob_get_clean();
        $this->Part++;
    }

    // Shorthand for:
    // add_row();
    // add_part();
    // You should only use this function if the row is only going to have one part in it (convention),
    // although it will theoretically work with multiple parts.
    function add_whole_row(){
        $this->Part = 0;
        $this->Page[$this->Row] = array();
        ob_start();
    }

    // Shorthand for:
    // end_part();
    // end_row();
    // You should only use this function if the row is only going to have one part in it (convention),
    // although it will theoretically work with multiple parts.
    function end_whole_row(){
        $this->Page[$this->Row][$this->Part]=ob_get_clean();
        $this->Row++;
    }

    // Set a variable that will only be availabe when the system is on its row
    // This variable is stored in the same way as pages, so don't use an integer for the $Key.
    function set_row_value($Key, $Value){
        $this->Page[$this->Row][$Key] = $Value;
    }

    // Set a variable that will always be available, no matter what row the system is on.
    // This variable is stored in the same way as rows, so don't use an integer for the $Key.
    function set_constant_value($Key, $Value){
        $this->Page[$Key] = $Value;
    }

    // Inserts a 'false' value into a row, which breaks out of while loops.
    // This is not necessary if the end of $this->Page is also the end of the while loop.
    function break_loop(){
        if(count($this->Page)>0){
            $this->Page[$this->Row] = FALSE;
            $this->Row++;
        }
    }

    //---------- Locking functions ----------//

    // These functions 'lock' a key.
    // Users cannot proceed until it is unlocked.

    function lock($Key){
        $this->cache_value('lock_'.$Key, 'true', 3600);
    }

    function unlock($Key) {
//        $this->delete('lock_'.$Key);
        $this->redis->del('lock_'.$Key);
    }

    //---------- Caching functions ----------//

    // Cache $this->Page and resets $this->Row and $this->Part
    function cache_page(){
        $this->cache_value($this->MemKey,$this->Page, $this->Duration);
        $this->Row = 0;
        $this->Part = 0;
    }

    // Exact same as cache_page, but does not store the page in cache
    // This is so that we can use classes that normally cache values in
    // situations where caching is not required
    function setup_page(){
        $this->Row = 0;
        $this->Part = 0;
    }

    // Wrapper for Memcache::set, with the zlib option removed and default duration of 1 hour
    function cache_value($Key, $Value, $Duration = 3600){
        $Value = $this->serialize($Value);
//        $this->set($Key,$Value, 0, $Duration);
        $this->redis->set($Key, $Value, $Duration);
        $this->cacheWriteTimes++;
        $this->keyHits['write'][$Key] = !isset($this->keyHits['write'][$Key]) ? 1 : $this->keyHits['write'][$Key]+1;
    }

    //---------- Getting functions ----------//

    // Returns the next row in the page
    // If there's only one part in the row, return that part.
    function next_row(){
        $this->Row++;
        $this->Part = 0;
        if(!isset($this->Page[$this->Row]) || $this->Page[$this->Row] == false){
            return false;
        }
        elseif(count($this->Page[$this->Row]) == 1){
            return $this->Page[$this->Row][0];
        }
        else {
            return $this->Page[$this->Row];
        }
    }

    // Returns the next part in the row
    function next_part(){
        $Return = $this->Page[$this->Row][$this->Part];
        $this->Part++;
        return $Return;
    }

    // Returns a 'row value' (a variable that changes for each row - see above).
    function get_row_value($Key){
        return $this->Page[$this->Row][$Key];
    }

    // Returns a 'constant value' (a variable that doesn't change with the rows - see above)
    function get_constant_value($Key){
        return $this->Page[$Key];
    }

    // If a cached version of the page exists, set $this->Page to it and return true.
    // Otherwise, return false.
    function get_page(){
        $Result = $this->get_value($this->MemKey);
        if($Result){
            $this->Row = 0;
            $this->Part = 0;
            $this->Page = $Result;
            return true;
        } else {
            return false;
        }
    }

    // Wrapper for Memcache::get. Why? Because wrappers are cool.
    function get_value($Key) {
        if($this->getClearCache()){
            $this->delete_value($Key);
            return false;
        }
        // If we've locked it
        // Xia Zuojie: we disable the following lock feature 'cause we don't need it and it doubles the time to fetch a value from a key
        /*while($Lock = $this->get('lock_'.$Key)){
            sleep(2);
        }*/

        $Return = $this->redis->get($Key);
        $Return = ! is_null($Return) ? $this->unserialize($Return) : null;
        $this->cacheReadTimes++;
        $this->keyHits['read'][$Key] = !isset($this->keyHits['read'][$Key]) ? 1 : $this->keyHits['read'][$Key]+1;
        return $Return;
    }

    // Wrapper for Memcache::delete. For a reason, see above.
    function delete_value($Key, $AllLang = false){
        if ($AllLang){
            $langfolder_array = $this->getLanguageFolderArray();
            foreach($langfolder_array as $lf)
                $this->redis->del($lf."_".$Key);
        }
        else {
            $this->redis->del($Key);
        }
    }

    function getCacheReadTimes() {
        return $this->cacheReadTimes;
    }

    function getCacheWriteTimes() {
        return $this->cacheWriteTimes;
    }

    function getKeyHits ($type='read') {
        return (array)$this->keyHits[$type];
    }

    /**
     * Serialize the value.
     *
     * @param  mixed  $value
     * @return mixed
     */
    protected function serialize($value)
    {
        return is_numeric($value) && ! in_array($value, [INF, -INF]) && ! is_nan($value) ? $value : serialize($value);
    }

    /**
     * Unserialize the value.
     *
     * @param  mixed  $value
     * @return mixed
     */
    protected function unserialize($value)
    {
        return is_numeric($value) ? $value : unserialize($value);
    }
}
