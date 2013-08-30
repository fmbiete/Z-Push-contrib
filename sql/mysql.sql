    /* SCHEMA
    
    CREATE TABLE USERS (USERNAME VARCHAR(50) NOT NULL, DEVID VARCHAR(50) NOT NULL, CREATED_AT DATETIME NOT NULL, UPDATED_AT DATETIME NOT NULL, PRIMARY KEY (USERNAME, DEVID));
    
    Unserialized example:
    array[user][devices]
    
    Add here the allowed permission control
    
    CREATE TABLE SETTINGS (KEY VARCHAR(50) NOT NULL, VALUE VARCHAR(50) NOT NULL, CREATED_AT DATETIME NOT NULL, UPDATED_AT DATETIME NOT NULL, PRIMARY KEY (KEY));
    
    Unserialized example:
    array["version"] = 2
    
    CREATE TABLE STATES (ID_STATE INTEGER AUTONUMERIC, DEVID VARCHAR(50) NOT NULL, KEY VARCHAR(50), TYPE VARCHAR(50), COUNTER INTEGER, DATA TEXT NOT NULL, 
            CREATED_AT DATETIME NOT NULL, UPDATED_AT DATETIME NOT NULL, PRIMARY KEY (ID_STATE));
    
    
    androidc655233820-06dcea58-66ec-4652-a612-46b288c40123-5        androidc655233820-842af46a-5aec-4af6-ab29-e3f500008e3b-fd
androidc655233820-06dcea58-66ec-4652-a612-46b288c40123-fd       androidc655233820-8578b068-5711-4a9e-9249-8c1b2ee6b69b-3
        $testkey = $devid . (($key !== false)? "-". $key : "") . (($type !== "")? "-". $type : "");
        if (preg_match('/^[a-zA-Z0-9-]+$/', $testkey, $matches) || ($type == "" && $key === false))
            $internkey = $testkey . (($counter && is_int($counter))?"-".$counter:"");
        
        [androidc655233820]-06dcea58-66ec-4652-a612-46b288c40123-5
        [devid]-[key]?-[type]?-[counter]?
        key is string or null/empty
        type is string or null/empty
        counter is int or null/empty
    */