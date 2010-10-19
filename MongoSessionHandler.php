<?php
/**
 * Uses MongoDB as a session handler in PHP
 * 
 */
class MongoSessionHandler
{
    /** @var MongoSessionHandler */
    protected static $_instance;

    /** @var MongoCollection */
    protected $_mongo;

    /**
     * Default options for the connection
     *
     * @var array
     */
    protected $_defaults = array(
        'servers' => array('localhost:27017'),
        'options' => array(
            'timeout' => 10, // ms
            'persist' => 'mongo-session'
        )
    );


    /**
     * Instantiate
     *
     * @param string $db
     * @param string $collection
     * @param array $config for the mongo connection
     */
    protected function __construct($db, $collection, Array $config)
    {
        $conf = (empty($config)) ? $this->_defaults : $config; 
        $uri = 'mongodb://'.implode(',', $conf['servers']); 
        
        $mongo = new Mongo($uri, $conf['options']);
        $this->_mongo = $mongo->selectCollection($db, $collection);

        $this->_mongo->ensureIndex(array('_id' => true, 'lock' => true));
        $this->_mongo->ensureIndex(array('expire' => true));
    }

    /**
     * Gets the current instance
     *
     * @return MongoSessionHandler null if register() has not been called yet
     */
    public static function getInstance()
    {
        return self::$_instance;
    }

    /**
     * Registers the handler into PHP
     * @param string $db
     * @param string $collection
     * @param array $config
     */
    public static function register($db, $collection, $config = array())
    {
        $m = new self($db, $collection, $config);
        self::$_instance = $m;

        // boom.
        session_set_save_handler(
            array($m, 'open'),
            array($m, 'close'),
            array($m, 'read'),
            array($m, 'write'),
            array($m, 'destroy'),
            array($m, 'gc')
        );
    }

    /**
     * Gets a global (across *all* machines) lock on the session
     *
     * @param string $id session id
     */
    protected function _lock($id)
    {
        $remaining = 30000000; // 30 seconds timeout, 30Million microsecs
        $timeout = 5000; // 5000 microseconds (5 ms)

        $start = microtime(true);
        do {
            try {
                $query  = array('_id' => $id, 'lock' => 0);
                $update = array('$set' => array('lock' => 1));
                $options = array('safe' => true, 'upsert' => true);
                $result = $this->_mongo->update($query, $update, $options);

                if ($result['ok'] == 1) {
                    return true; 
                }
                
            } catch (MongoCursorException $e) {
                if (substr($e->getMessage(), 0, 26) != 'E11000 duplicate key error') {
                    throw $e;  // not a dup key?
                }
            }

            usleep($timeout);
            $remaining = $remaining - $timeout;

            // wait a little longer next time, 1 sec max wait
            $timeout = ($timeout < 1000000) ? $timeout * 2 : 1000000;

        } while ($remaining > 0);

        throw new Exception('Could not get session lock');
    }

    /**
     * Releases the lock on the session
     *
     * @param string $id
     *
     */
    protected function _unlock($id)
    {
        $query  = array('_id' => $id);
        $update = array('$set' => array('lock' => 0));
        $options = array('safe' => true);
        $result = $this->_mongo->update($query, $update, $options);
    }

    /**
     * Returns the MongoCollection instance
     * 
     * @return MongoCollection
     */
    public function mongo()
    {
        return $this->_mongo;
    }

    /**
     * Open the session, do nothing as we already have a
     * connection to mongo.
     *
     * @param string $path
     * @param string $name
     * @return bool
     */
    public function open($path, $name)
    {
        return true; 
    }

    /**
     * Does nothing.
     *
     * @return bool
     */
    public function close()
    {
        return true;
    }


    /**
     * Reads the session from Mongo, create a document if it
     * doesn't exist. 
     *
     * @param string $id
     * @return string
     */
    public function read($id)
    {
        $this->_lock($id);
        $doc = $this->_mongo->findOne(array('_id' => $id));
        if (!isset($doc['d'])) {
            return '';
        } else {
            return $doc['d'];
        }
    }

    /**
     * Writes the session data back to mongo
     * 
     * @param string $id
     * @param string $data
     * @return bool
     */
    public function write($id, $data)
    {
        $doc = array(
            '_id'       => $id,
            'lock'      => 0,
            'd'         => $data,
            'expire'    => time() + intval(ini_get('session.gc_maxlifetime'))
        );
        $options = array('safe' => true, 'upsert' => true);

        $result = $this->_mongo->update(array('_id' => $id), $doc, $options);

        return (!$result['ok'] == 1);
    }

    /**
     * Destroy's the session
     *
     * @param string id
     * @return bool
     */
    public function destroy($id)
    {
        $result = $this->_mongo->remove(array('_id' => $id), array('safe' => true));
        return ($result['ok'] == 1); 
    }

    /**
     * Triggers the garbage collector, we do this with a mongo
     * safe=0 delete, as that will return immediately without
     * blocking php
     *
     * @return bool
     */
    public function gc($max)
    {
        $results = $this->_mongo->remove(
            array('expire' => array('$lt' => time()))
        );
    }
}