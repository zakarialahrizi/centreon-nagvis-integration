<?php
/*****************************************************************************
 *
 * GlobalBackendcentreonbroker.php - backend class for handling object and state
 *                           information stored in the Centreon Broker database.
 *
 * Copyright (c) 2004-2011 NagVis Project (Contact: info@nagvis.org)
 *
 * License:
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2 as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 *
 *****************************************************************************/

/**
 * @author  Maximilien Bersoult <mbersoult@merethis.com>
 * @author  Mathieu Parent <math.parent@gmail.com>
 */
class GlobalBackendcentreonbroker implements GlobalBackendInterface {
    private $_backendId = 0;
    private $_dbname;
    private $_dbuser;
    private $_dbpass;
    private $_dbhost;
    private $_dbport;
    private $_dbinstancename;

    private $_dbh;

    private $_instanceId = 0;
    private $_cacheHostId = array();
    private $_cacheServiceId = array();
    private $_cacheHostAck = array();

    private static $_validConfig = array(
        'dbhost' => array(
            'must' => 1,
            'editable' => 1,
            'default' => 'localhost',
            'match' => MATCH_STRING_NO_SPACE
        ),
        'dbport' => array(
            'must' => 0,
            'editable' => 1,
            'default' => '3306',
            'match' => MATCH_INTEGER
        ),
        'dbname' => array(
            'must' => 1,
            'editable' => 1,
            'default' => 'centreon_storage',
            'match' => MATCH_STRING_NO_SPACE
        ),
        'dbuser' => array(
            'must' => 1,
            'editable' => 1,
            'default' => 'centreon',
            'match' => MATCH_STRING_NO_SPACE
        ),
        'dbpass' => array(
            'must' => 0,
            'editable' => 1,
            'default' => '',
            'match' => MATCH_STRING_NO_SPACE
        ),
        'dbinstancename' => array(
            'must' => 0,
            'editable' => 1,
            'default' => 'default',
            'match' => MATCH_STRING_NO_SPACE
        )
    );

    /**
     * Constructor
     *
     * @author Maximilien Bersoult <mbersoult@merethis.com>
     * @param int $backendId The backend ID
     */
    public function __construct($backendId) {
        $this->_backendId = $backendId;

        $this->_dbname = cfg('backend_'.$backendId, 'dbname');
        $this->_dbuser = cfg('backend_'.$backendId, 'dbuser');
        $this->_dbpass = cfg('backend_'.$backendId, 'dbpass');
        $this->_dbhost = cfg('backend_'.$backendId, 'dbhost');
        $this->_dbport = cfg('backend_'.$backendId, 'dbport');
        $this->_dbinstancename = cfg('backend_'.$backendId, 'dbinstancename');

        $this->connectToDb();
        $this->loadInstanceId();
        error_log("Backend {$backendId} initialized. Instance ID: " . $this->_instanceId);
    }

    /**
     * Return the valid config for this backend
     *
     * @author Maximilien Bersoult <mbersoult@merethis.com>
     * @return array
     */
    static public function getValidConfig() {
        return self::$_validConfig;
    }

    /**
     * Get the list of objects
     *
     * @author Maximilien Bersoult <mbersoult@merethis.com>
     * @param string $type The object type
     * @param string $name1Pattern The object name (host name or hostgroup name or servicegroup name)
     * @param string $name2Pattern Service name for a object type service
     * @return array
     * @throws BackendException
     */
    public function getObjects($type, $name1Pattern = '', $name2Pattern = '') {
        $ret = array();
        switch ($type) {
            case 'host':
                $queryGetObject = 'SELECT host_id, 0 as service_id, name as name1, "" as name2
                    FROM hosts
                    WHERE enabled = 1';
                if ($name1Pattern != '') {
                    $queryGetObject .= ' AND name = %s';
                }
                break;
            case 'service':
                $queryGetObject = 'SELECT s.host_id, s.service_id, h.name as name1, s.description as name2
                    FROM services s, hosts h
                    WHERE h.enabled =1
                        AND s.enabled = 1
                        AND h.name = %s
                        AND h.host_id = s.host_id';
                if ('' !== $name2Pattern) {
                    $queryGetObject .= ' AND s.description = %s';
                }
                break;
            case 'hostgroup':
                $queryGetObject = 'SELECT 0 as host_id, 0 as service_id, name as name1, "" as name2
                    FROM hostgroups
                    WHERE 1 = 1';
                if ($name1Pattern != '') {
                    $queryGetObject .= ' AND name = %s';
                }
                break;
            case 'servicegroup':
                $queryGetObject = 'SELECT 0 as host_id, 0 as service_id, name as name1, "" as name2
                    FROM servicegroups
                    WHERE 1 = 1';
                if ($name1Pattern != '') {
                    $queryGetObject .= ' AND name = %s';
                }
                break;
            default:
                return array();
        }
        /* Add instance id, enabled and order */
        if ($this->_instanceId != 0) {
             $queryGetObject .= ' AND instance_id = ' . $this->_instanceId;
        }
        $queryGetObject .= ' ORDER BY name1, name2';

        if ('' !== $name2Pattern) {
            $queryGetObject = sprintf($queryGetObject, $this->_dbh->quote($name1Pattern), $this->_dbh->quote($name2Pattern), $this->_instanceId);
        }
        if ('' !== $name1Pattern) {
            $queryGetObject = sprintf($queryGetObject, $this->_dbh->quote($name1Pattern), $this->_instanceId);
        }

        try {
            $stmt = $this->_dbh->query($queryGetObject);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingObject', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            /* Set cache */
            if (0 != $row['host_id']) {
                $this->_cacheHostId[$row['name1']] = $row['host_id'];
                if (0 != $row['service_id']) {
                    $this->_cacheServiceId[$row['name1']][$row['name2']] = $row['service_id'];
                }
            }
            /* Set table */
            $ret[] = array('name1' => $row['name1'], 'name2' => $row['name2']);
        }
        error_log("Executing query: " . $queryGetObject);

        return $ret;
    }

    /**
     * Get host state object
     *
     * @param type $objects
     * @param type $options
     * @param type $filters
     * @return array
     */
    public function getHostState($objects, $options, $filters) {
        if (empty($objects)) {
            return array(); // Empêche une clause SQL invalide du type IN ()
        }
        $queryGetHostState = 'SELECT
            h.name AS alias,
            h.name,
            h.address,
            h.statusmap_image,
            h.notes,
            h.check_command,
            h.perfdata,
            h.last_check,
            h.next_check,
            h.state_type,
            h.check_attempt as current_check_attempt,
            h.max_check_attempts,
            h.last_state_change,
            h.last_hard_state,
            h.last_hard_state_change,
            h.checked as has_been_checked,
            h.state as current_state,
            h.output,
            h.acknowledged as problem_has_been_acknowledged,
            d.start_time as downtime_start,
            d.end_time as downtime_end,
            d.author as downtime_author,
            d.comment_data as downtime_data
            FROM hosts h
            LEFT JOIN downtimes d 
                ON (d.host_id = h.host_id AND d.service_id IS NULL AND d.start_time < UNIX_TIMESTAMP() AND d.end_time > UNIX_TIMESTAMP() AND d.deletion_time IS NULL)
            WHERE (d.downtime_id IS NULL OR d.downtime_id IN (
                            SELECT MAX(d.downtime_id) as downtime_id
                                FROM downtimes d where d.host_id = h.host_id AND d.service_id IS NULL AND d.start_time < UNIX_TIMESTAMP() AND d.end_time > UNIX_TIMESTAMP() AND d.deletion_time IS NULL
                            ) 
                  )
                  AND h.enabled = 1 AND (%s)';
        if ($this->_instanceId != 0) {
            $queryGetHostState .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryGetHostState = sprintf($queryGetHostState, $this->parseFilter($objects, $filters));

        try {
            $stmt = $this->_dbh->query($queryGetHostState);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingHostState', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $arrReturn = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            /* Modifiy for downtime */
            if (empty($row['downtime_start'])) {
                $row['downtime_start'] = null;
                $row['downtime_end'] = null;
                $row['downtime_author'] = null;
                $row['downtime_data'] = null;
                $in_downtime = false;
            } else {
                $in_downtime = true;
            }
            /* Modify state */
            /* Only Hard */
            if ($options & 1) {
                if ($row['state_type'] == '0') {
                    $row['current_state'] = $row['last_hard_state'];
                }
            }
            /* Unchecked state */
            if ($row['has_been_checked'] == '0' || $row['current_state'] == '') {
                $arrReturn[$row['name']] = Array(
                    UNCHECKED,
                    l('hostIsPending', Array('HOST' => $row['name'])),
                    null,
                    null,
                    null,
                );
                continue;
            } else {
                switch ($row['current_state']) {
                    case '0':
                        $state = UP;
                        break;
                    case '1':
                        $state = DOWN;
                        break;
                    case '2':
                        $state = UNREACHABLE;
                        break;
                    case '3':
                        $state = UNKNOWN;
                        break;
                    default:
                        $state = UNKNOWN;
                        $row['output'] = 'GlobalBackendcentreonbroker::getHostState: Undefined state!';
                        break;
                }
            }
            $acknowledged = $state != UP && $row['problem_has_been_acknowledged'];
            $arrReturn[$row['name']] = Array(
                $state,
                $row['output'],  // output
                $row['problem_has_been_acknowledged'],
                $in_downtime,
                0, // staleness
                $row['state_type'],  // state type
                $row['current_check_attempt'],  // current attempt
                $row['max_check_attempts'], // max attempts
                $row['last_check'],  // last check
                $row['next_check'],  // next check
                $row['last_hard_state_change'], // last hard state change
                $row['last_state_change'], // last state change
                $row['perfdata'], // perfdata
                $row['name'],  // display name
                $row['alias'], // alias
                $row['address'],  // address
                $row['notes'],  // notes
                $row['check_command'], // check command
                Array(), // Custom vars
                $row['downtime_author'], // downtime author
                $row['downtime_data'], // downtime comment
                $row['downtime_start'], // downtime start
                $row['downtime_end'], // downtime end
            );
        }
        return $arrReturn;
    }

    /**
     * Get service state object
     *
     * @param type $objects
     * @param type $options
     * @param type $filters
     * @return array
     */
    public function getServiceState($objects, $options, $filters) {
        if (empty($objects)) {
            return array(); 
        }
        $queryGetServiceState = 'SELECT
            h.host_id,
            h.name,
            h.address,
            s.checked as has_been_checked,
            s.description as service_description,
            s.display_name,
            s.display_name as alias,
            s.notes,
            s.check_command,
            s.perfdata,
            s.output,
            s.state as current_state,
            s.last_check,
            s.next_check,
            s.state_type,
            s.check_attempt as current_check_attempt,
            s.max_check_attempts,
            s.last_state_change,
            s.last_hard_state,
            s.last_hard_state_change,
            s.acknowledged as problem_has_been_acknowledged,
            d.start_time as downtime_start,
            d.end_time as downtime_end,
            d.author as downtime_author,
            d.comment_data as downtime_data
            FROM services s
            LEFT JOIN hosts h
                ON s.host_id=h.host_id
            LEFT JOIN downtimes d 
                ON (d.host_id = h.host_id AND d.service_id=s.service_id AND d.start_time < UNIX_TIMESTAMP() AND d.end_time > UNIX_TIMESTAMP() AND d.deletion_time IS NULL)
            WHERE (d.downtime_id IS NULL OR d.downtime_id IN (
                            SELECT MAX(d.downtime_id) as downtime_id
                                FROM downtimes d where d.host_id = h.host_id AND d.service_id = s.service_id AND d.start_time < UNIX_TIMESTAMP() AND d.end_time > UNIX_TIMESTAMP() AND d.deletion_time IS NULL
                            ) 
                  )
               AND s.host_id = h.host_id AND s.enabled = 1 AND h.enabled = 1
               AND (%s)';
        if ($this->_instanceId != 0) {
            $queryGetServiceState .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryGetServiceState = sprintf($queryGetServiceState, $this->parseFilter($objects, $filters));

        try {
            $stmt = $this->_dbh->query($queryGetServiceState);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingServiceState', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $listStates = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            /* Define key */
            $specific = false;
            $key = $row['name'];
            if (isset($objects[$key . '~~' . $row['service_description']])) {
                $key = $key . '~~' . $row['service_description'];
                $specific = true;
            }
            /* Modifiy for downtime */
            if (empty($row['downtime_start'])) {
                $row['downtime_start'] = null;
                $row['downtime_end'] = null;
                $row['downtime_author'] = null;
                $row['downtime_data'] = null;
                $in_downtime = false;
            } else {
                $in_downtime = true;
            }
            /* Modify state */
            /* Only Hard */
            if ($options & 1) {
                if ($row['state_type'] == '0') {
                    $row['current_state'] = $row['last_hard_state'];
                }
            }
            /* Get host ack */
            $row['problem_has_been_acknowledged'] = max(
                (int)$row['problem_has_been_acknowledged'],
                $this->getHostAckByHost($row['host_id'])
            );
            unset($row['host_id']);

            /* Unchecked state */
            if ($row['has_been_checked'] == '0' || $row['current_state'] == '') {
                $svc = array_fill(0, EXT_STATE_SIZE, null);
                $svc[STATE]  = PENDING;
                $svc[OUTPUT] = l('serviceNotChecked', Array('SERVICE' => $row['service_description']));
            } else {
                switch ($row['current_state']) {
                    case '0':
                        $state = OK;
                        break;
                    case '1':
                        $state = WARNING;
                        break;
                    case '2':
                        $state = CRITICAL;
                        break;
                    case '3':
                        $state = UNKNOWN;
                        break;
                    default:
                        $state = UNKNOWN;
                        $row['output'] = 'GlobalBackendcentreonbroker::getHostState: Undefined state!';
                        break;
                }
                $svc = array(
                    $state,
                    $row['output'],  // output
                    $row['problem_has_been_acknowledged'],
                    $in_downtime,
                    0, // staleness
                    $row['state_type'],  // state type
                    $row['current_check_attempt'],  // current attempt
                    $row['max_check_attempts'], // max attempts
                    $row['last_check'],  // last check
                    $row['next_check'],  // next check
                    $row['last_hard_state_change'], // last hard state change
                    $row['last_state_change'], // last state change
                    $row['perfdata'], // perfdata
                    $row['name'],  // display name
                    $row['alias'], // alias
                    $row['address'],  // address
                    $row['notes'],  // notes
                    $row['check_command'], // check command
                    Array(), // Custom vars
                    $row['downtime_author'], // downtime author
                    $row['downtime_data'], // downtime comment
                    $row['downtime_start'], // downtime start
                    $row['downtime_end'], // downtime end
                    $row['service_description'], // descr
                );
            }
            if ($specific) {
                $listStates[$key] = $svc;
            } else {
                if (!isset($listStates[$key])) {
                    $listStates[$key] = array();
                }
                $listStates[$key][] = $svc;
            }
        }
        return $listStates;
    }

    /**
     * Get host member counts
     *
     * @param type $objects
     * @param type $options
     * @param type $filters
     * @return array
     */
    public function getHostMemberCounts($objects, $options, $filters) {
        if (empty($objects)) {
            return array(); // Empêche une clause SQL invalide du type IN ()
        }
        if($options & 1) {
            $stateAttr = 'IF((s.state_type = 0), s.last_hard_state, s.state)';
        } else {
            $stateAttr = 's.state';
        }
        $queryCount = 'SELECT
            h.name,
            h.name AS alias,
            SUM(IF(s.checked=0,1,0)) AS pending,
            SUM(IF(('.$stateAttr.'=0 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND h.scheduled_downtime_depth=0),1,0)) AS ok,
            SUM(IF(('.$stateAttr.'=0 AND s.checked!=0 AND (s.scheduled_downtime_depth!=0 OR h.scheduled_downtime_depth!=0)),1,0)) AS ok_downtime,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND h.scheduled_downtime_depth=0 AND s.acknowledged=0 AND h.acknowledged=0),1,0)) AS warning,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND (s.scheduled_downtime_depth!=0 OR h.scheduled_downtime_depth!=0)),1,0)) AS warning_downtime,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND (s.acknowledged=1 OR h.acknowledged=1)),1,0)) AS warning_ack,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND h.scheduled_downtime_depth=0) AND s.acknowledged=0 AND h.acknowledged=0,1,0)) AS critical,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND (s.scheduled_downtime_depth!=0 OR h.scheduled_downtime_depth!=0)),1,0)) AS critical_downtime,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND (s.acknowledged=1 OR h.acknowledged=1)),1,0)) AS critical_ack,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND h.scheduled_downtime_depth=0 AND s.acknowledged=0 AND h.acknowledged=0),1,0)) AS unknown,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND (s.scheduled_downtime_depth!=0 OR h.scheduled_downtime_depth!=0)),1,0)) AS unknown_downtime,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND (s.acknowledged=1 OR h.acknowledged=1)),1,0)) AS unknown_ack
            FROM hosts h, services s
            WHERE h.host_id = s.host_id AND h.enabled = 1 AND s.enabled = 1
                AND (%s)';
        if ($this->_instanceId != 0) {
            $queryCount .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryCount .= ' GROUP BY h.name';
        $queryCount = sprintf($queryCount, $this->parseFilter($objects, $filters));
        
        try {
            $stmt = $this->_dbh->query($queryCount);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingHostStateCount', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $counts = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['name']] = array(
                'counts' => array(
                    PENDING => array(
                        'normal' => intval($row['pending']),
                    ),
                    OK => array(
                        'normal'   => intval($row['ok']),
                        'downtime' => intval($row['ok_downtime']),
                    ),
                    WARNING => array(
                        'normal'   => intval($row['warning']),
                        'ack'      => intval($row['warning_ack']),
                        'downtime' => intval($row['warning_downtime']),
                    ),
                    CRITICAL => array(
                        'normal'   => intval($row['critical']),
                        'ack'      => intval($row['critical_ack']),
                        'downtime' => intval($row['critical_downtime']),
                    ),
                    UNKNOWN => array(
                        'normal'   => intval($row['unknown']),
                        'ack'      => intval($row['unknown_ack']),
                        'downtime' => intval($row['unknown_downtime']),
                    )
                )
            );
        }
        return $counts;
    }

    /**
     * Get hostgroup state counts
     *
     * @param type $objects
     * @param type $options
     * @param type $filters
     * @return array
     */
    public function getHostgroupStateCounts($objects, $options, $filters) {
        if (empty($objects)) {
            return array(); // Empêche une clause SQL invalide du type AND ()
        }
        if($options & 1) {
            $stateAttr = 'IF((h.state_type = 0), h.last_hard_state, h.state)';
        } else {
            $stateAttr = 'h.state';
        }
        $queryCount = 'SELECT
            hg.name,
            hg.name AS alias,
            SUM(IF(h.checked=0,1,0)) AS unchecked,
            SUM(IF(('.$stateAttr.'=0 AND h.checked!=0 AND h.scheduled_downtime_depth=0),1,0)) AS up,
            SUM(IF(('.$stateAttr.'=0 AND h.checked!=0 AND h.scheduled_downtime_depth!=0),1,0)) AS up_downtime,
            SUM(IF(('.$stateAttr.'=1 AND h.checked!=0 AND h.scheduled_downtime_depth=0 AND h.acknowledged=0),1,0)) AS down,
            SUM(IF(('.$stateAttr.'=1 AND h.checked!=0 AND h.scheduled_downtime_depth!=0),1,0)) AS down_downtime,
            SUM(IF(('.$stateAttr.'=1 AND h.checked!=0 AND h.acknowledged=1),1,0)) AS down_ack,
            SUM(IF(('.$stateAttr.'=2 AND h.checked!=0 AND h.scheduled_downtime_depth=0 AND h.acknowledged=0),1,0)) AS unreachable,
            SUM(IF(('.$stateAttr.'=2 AND h.checked!=0 AND h.scheduled_downtime_depth!=0),1,0)) AS unreachable_downtime,
            SUM(IF(('.$stateAttr.'=2 AND h.checked!=0 AND h.acknowledged=1),1,0)) AS unreachable_ack,
            SUM(IF(('.$stateAttr.'=3 AND h.checked!=0 AND h.scheduled_downtime_depth=0 AND h.acknowledged=0),1,0)) AS unknown,
            SUM(IF(('.$stateAttr.'=3 AND h.checked!=0 AND h.scheduled_downtime_depth!=0),1,0)) AS unknown_downtime,
            SUM(IF(('.$stateAttr.'=3 AND h.checked!=0 AND h.acknowledged=1),1,0)) AS unknown_ack
            FROM hostgroups hg, hosts_hostgroups hhg, hosts h
            WHERE hhg.hostgroup_id = hg.hostgroup_id
                AND hhg.host_id = h.host_id 
                AND h.enabled = 1 
                AND (%s)';
        if ($this->_instanceId != 0) {
            $queryCount .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryCount .= ' GROUP BY hg.name';
        $queryCount = sprintf($queryCount, $this->parseFilter($objects, $filters, 'hg'));
        
        try {
            $stmt = $this->_dbh->query($queryCount);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettinggetHostgroupStateCounts', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $counts = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['name']] = array(
                'details' => array(ALIAS => $row['alias']),
                'counts' => array(
                    UNCHECKED => array(
                        'normal'    => intval($row['unchecked']),
                    ),
                    UP => array(
                        'normal'    => intval($row['up']),
                        'downtime'  => intval($row['up_downtime']),
                    ),
                    DOWN => array(
                        'normal'    => intval($row['down']),
                        'ack'       => intval($row['down_ack']),
                        'downtime'  => intval($row['down_downtime']),
                    ),
                    UNREACHABLE => array(
                        'normal'    => intval($row['unreachable']),
                        'ack'       => intval($row['unreachable_ack']),
                        'downtime'  => intval($row['unreachable_downtime']),
                    )
                )
            );
        }
        if ($options & 2) {
            return $counts;
        }

        if ($options & 1) {
            $stateAttr = 'IF((s.state_type = 0), s.last_hard_state, s.state)';
        } else {
            $stateAttr = 's.state';
        }
        $queryCount = 'SELECT
            hg.name,
            hg.name AS alias,
            SUM(IF(s.checked=0,1,0)) AS pending,
            SUM(IF(('.$stateAttr.'=0 AND s.checked!=0 AND s.scheduled_downtime_depth=0),1,0)) AS ok,
            SUM(IF(('.$stateAttr.'=0 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS ok_downtime,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND s.acknowledged=0),1,0)) AS warning,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS warning_downtime,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.acknowledged=1),1,0)) AS warning_ack,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND s.acknowledged=0),1,0)) AS critical,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS critical_downtime,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.acknowledged=1),1,0)) AS critical_ack,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND s.acknowledged=0),1,0)) AS unknown,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS unknown_downtime,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.acknowledged=1),1,0)) AS unknown_ack
            FROM hostgroups hg, hosts_hostgroups hhg, services s, hosts h
            WHERE hhg.hostgroup_id = hg.hostgroup_id
                AND hhg.host_id = s.host_id
                AND hhg.host_id = h.host_id
                AND h.enabled = 1
                AND s.enabled = 1
                AND (%s)';
        if ($this->_instanceId != 0) {
            $queryCount .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryCount .= ' GROUP BY hg.name';
        $queryCount = sprintf($queryCount, $this->parseFilter($objects, $filters, 'hg'));
        
        try {
            $stmt = $this->_dbh->query($queryCount);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettinggetHostgroupStateCounts', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['name']]['counts'][PENDING]['normal']    = intval($row['pending']);
            $counts[$row['name']]['counts'][OK]['normal']         = intval($row['ok']);
            $counts[$row['name']]['counts'][OK]['downtime']       = intval($row['ok_downtime']);
            $counts[$row['name']]['counts'][WARNING]['normal']    = intval($row['warning']);
            $counts[$row['name']]['counts'][WARNING]['ack']       = intval($row['warning_ack']);
            $counts[$row['name']]['counts'][WARNING]['downtime']  = intval($row['warning_downtime']);
            $counts[$row['name']]['counts'][CRITICAL]['normal']   = intval($row['critical']);
            $counts[$row['name']]['counts'][CRITICAL]['ack']      = intval($row['critical_ack']);
            $counts[$row['name']]['counts'][CRITICAL]['downtime'] = intval($row['critical_downtime']);
            $counts[$row['name']]['counts'][UNKNOWN]['normal']    = intval($row['unknown']);
            $counts[$row['name']]['counts'][UNKNOWN]['ack']       = intval($row['unknown_ack']);
            $counts[$row['name']]['counts'][UNKNOWN]['downtime']  = intval($row['unknown_downtime']);
        }
        return $counts;
    }

    /**
     * Get servicegroup state counts
     *
     * @param type $objects
     * @param type $options
     * @param type $filters
     * @return array
     */
    public function getServicegroupStateCounts($objects, $options, $filters) {
        if (empty($objects)) {
            return array(); // Empêche une clause SQL invalide du type AND ()
        }
        if($options & 1) {
            $stateAttr = 'IF((s.state_type = 0), s.last_hard_state, s.state)';
        } else {
            $stateAttr = 's.state';
        }
        $queryCount = 'SELECT
            sg.name,
            sg.name AS alias,
            SUM(IF(s.checked=0,1,0)) AS pending,
            SUM(IF(('.$stateAttr.'=0 AND s.checked!=0 AND s.scheduled_downtime_depth=0),1,0)) AS ok,
            SUM(IF(('.$stateAttr.'=0 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS ok_downtime,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND s.acknowledged=0),1,0)) AS warning,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS warning_downtime,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.acknowledged=1),1,0)) AS warning_ack,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND s.acknowledged=0),1,0)) AS critical,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS critical_downtime,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.acknowledged=1),1,0)) AS critical_ack,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND s.acknowledged=0),1,0)) AS unknown,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS unknown_downtime,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.acknowledged=1),1,0)) AS unknown_ack
            FROM servicegroups sg, services_servicegroups ssg, services s
            WHERE ssg.servicegroup_id = sg.servicegroup_id
                AND ssg.service_id = s.service_id
                AND s.enabled = 1
                AND (%s) GROUP BY sg.name';
        $queryCount = sprintf($queryCount, $this->parseFilter($objects, $filters, 'sg'));

        try {
            $stmt = $this->_dbh->query($queryCount);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingServicegroupStateCounts', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $counts = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['name']] = array(
                'details' => array(ALIAS => $row['alias']),
                'counts' => array(
                    PENDING => array(
                        'normal'   => intval($row['pending']),
                    ),
                    OK => array(
                        'normal'   => intval($row['ok']),
                        'downtime' => intval($row['ok_downtime']),
                    ),
                    WARNING => array(
                        'normal'   => intval($row['warning']),
                        'ack'      => intval($row['warning_ack']),
                        'downtime' => intval($row['warning_downtime']),
                    ),
                    CRITICAL => array(
                        'normal'   => intval($row['critical']),
                        'ack'      => intval($row['critical_ack']),
                        'downtime' => intval($row['critical_downtime']),
                    ),
                    UNKNOWN => array(
                        'normal'   => intval($row['unknown']),
                        'ack'      => intval($row['unknown_ack']),
                        'downtime' => intval($row['unknown_downtime']),
                    )
                )
            );
        }

        return $counts;
    }

    /**
     * Get host names with no parent
     *
     * @return array
     * @throws BackendException
     */
    public function getHostNamesWithNoParent() {
        $queryNoParents = 'SELECT h.name
            FROM hosts h
            WHERE h.enabled = 1
            AND NOT EXISTS (
                SELECT 1
                FROM hosts_hosts_parents hp
                WHERE hp.child_id = h.host_id
            )';

        if ($this->_instanceId != 0) {
            $queryNoParents .= ' AND h.instance_id = :instanceId';
        }

        try {
            $stmt = $this->_dbh->prepare($queryNoParents);
            if ($this->_instanceId != 0) {
                $stmt->bindValue(':instanceId', $this->_instanceId, PDO::PARAM_INT);
            }
            $stmt->execute();
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingHostNamesWithNoParent', [
                'BACKENDID' => $this->_backendId,
                'ERROR' => $e->getMessage()
            ]));
        }

        $noParents = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $noParents[] = $row['name'];
        }
        return $noParents;
    }

    /**
     * Get direct child names by host name
     *
     * @param string $hostname
     * @return array
     * @throws BackendException
     */
    public function getDirectChildNamesByHostName($hostname) {
        $query = 'SELECT h.name
            FROM hosts h
            JOIN hosts_hosts_parents hp ON h.host_id = hp.child_id
            JOIN hosts parent ON hp.parent_id = parent.host_id
            WHERE h.enabled = 1 AND parent.name = :hostname';

        if ($this->_instanceId != 0) {
            $query .= ' AND h.instance_id = :instanceId';
        }

        try {
            $stmt = $this->_dbh->prepare($query);
            $stmt->bindValue(':hostname', $hostname, PDO::PARAM_STR);
            if ($this->_instanceId != 0) {
                $stmt->bindValue(':instanceId', $this->_instanceId, PDO::PARAM_INT);
            }
            $stmt->execute();
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingDirectChildNamesByHostName', [
                'BACKENDID' => $this->_backendId,
                'ERROR' => $e->getMessage()
            ]));
        }

        $childs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $childs[] = $row['name'];
        }
        return $childs;
    }

    /**
     * Get direct parent names by host name
     *
     * @param string $hostname
     * @return array
     * @throws BackendException
     */
    public function getDirectParentNamesByHostName($hostname) {
        $queryGetParents = 'SELECT h.name
            FROM hosts h, hosts_hosts_parents hp
            WHERE h.host_id = hp.parent_id
                AND h.enabled = 1
                AND hp.child_id IN (SELECT host_id
                    FROM hosts
                    WHERE name = "%s")';
        if ($this->_instanceId != 0) {
            $queryGetParents .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryGetParents = 'SELECT h.name
                            FROM hosts h, hosts_hosts_parents hp
                            WHERE h.host_id = hp.parent_id
                            AND h.enabled = 1
                            AND hp.child_id IN (SELECT host_id
                                FROM hosts
                                WHERE name = %s)';
        $queryGetParents = sprintf($queryGetParents, $this->_dbh->quote($hostname));
        
        try {
            $stmt = $this->_dbh->query($queryGetParents);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingDirectParentNamesByHostName', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $parents = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $parents[] = $row['name'];
        }
        return $parents;
    }

    /**
     * Get host acknowledgement status by host ID
     *
     * @param int $hostId
     * @return int
     * @throws BackendException
     */
    private function getHostAckByHost($hostId) {
        if (isset($this->_cacheHostAck[$hostId])) {
            return $this->_cacheHostAck[$hostId];
        }
        $queryAck = 'SELECT acknowledged
            FROM hosts
            WHERE enabled = 1 AND host_id = :hostId';
            
        try {
            $stmt = $this->_dbh->prepare($queryAck);
            $stmt->bindValue(':hostId', $hostId, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingHostAck', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }    

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (false === $row) {
            $this->_cacheHostAck[$hostId] = 0; // Cache negative result
            return 0;
        }
        
        $return = 0;
        if (isset($row['acknowledged']) && $row['acknowledged'] == '1') {
            $return = 1;
        }
        $this->_cacheHostAck[$hostId] = $return;
        return $this->_cacheHostAck[$hostId];
    }

    /**
     * Get program start time
     *
     * @return int
     */
    public function getProgramStart() {
        $sql = 'SELECT UNIX_TIMESTAMP(start_time) AS program_start
                FROM instances
                WHERE instance_id = :instance';

        $stmt = $this->_dbh->prepare($sql);
        $stmt->bindValue(':instance', $this->_instanceId, PDO::PARAM_INT);
        $stmt->execute();

        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data !== false && is_numeric($data['program_start'])) {
            return intval($data['program_start']);
        } else {
            return -1;
        }
    }

    /**
     * Parse filter conditions
     *
     * @param array $objects
     * @param array $filters
     * @param string $tableAlias
     * @return string
     * @throws BackendException
     */
    private function parseFilter($objects, $filters, $tableAlias = 'h') {
        if (empty($objects) || empty($filters)) {
            return '1=1';
        }
        $listKeys = array(
            'host_name',
            'host_groups',
            'service_groups',
            'hostgroup_name',
            'group_name',
            'groups',
            'servicegroup_name',
            'service_description'
        );
        $allFilters = array();
        foreach ($objects as $object) {
            $objFilters = array();
            /* Filters */
            foreach ($filters as $filter) {
                if (false === in_array($filter['key'], $listKeys)) {
                    throw new BackendException('Invalid filter key ('.$filter['key'].')');
                }
                if ($filter['op'] == '>=') {
                    $op = '=';
                } else {
                    $op = $filter['op'];
                }
                if ($filter['key'] == 'service_description') {
                    $key = 's.description';
                    $val = $object[0]->getServiceDescription();
                } else {
                    $key = $tableAlias . '.name';
                    $val = $object[0]->getName();
                }
                $objFilters[] = $key . ' ' . $op . ' ' . $this->_dbh->quote($val);
            }

            $allFilters[] = join(' AND ', $objFilters);
        }
        return join(' OR ', $allFilters);
    }

    /**
     * Connection to the Centreon Broker database
     *
     * @author Maximilien Bersoult <mbersoult@merethis.com>
     * @throws BackendConnectionProblem
     */
    private function connectToDb() {
        if (!extension_loaded('pdo') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new BackendConnectionProblem(l('pdo_mysqlNotSupported', array('BACKENDID' => $this->_backendId)));
        }
        $fullhost = 'host=' . $this->_dbhost;
        if ('' != $this->_dbport) {
            $fullhost .= ';port=' . $this->_dbport;
        }
        if ('' != $this->_dbname) {
            $fullhost .= ';dbname=' . $this->_dbname;
        }
        try {
            $this->_dbh = new PDO('mysql:' . $fullhost, $this->_dbuser, $this->_dbpass, array(
                PDO::ATTR_PERSISTENT => false,  // Explicitly disabled
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
            ));
            $this->_dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new BackendConnectionProblem(l('errorConnectingMySQL', Array('BACKENDID' => $this->_backendId,'MYSQLERR' => $e->getMessage())));
        }
    }

    /**
     * Load the instance id
     *
     * @author Maximilien Bersoult <mbersoult@merethis.com>
     * @throws BackendException
     */
    private function loadInstanceId() {
        $this->_instanceId = 0; // Default value
        
        try {
            $stmt = $this->_dbh->prepare("SELECT instance_id
                            FROM instances
                            WHERE name = :name");
            $stmt->bindParam(':name', $this->_dbinstancename, PDO::PARAM_STR);
            $stmt->execute();
            
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->_instanceId = filter_var($row['instance_id'], FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
            } else {
                // Log warning if instance name was specified but not found
                if ($this->_dbinstancename !== 'default') {
                    error_log("Instance '{$this->_dbinstancename}' not found, using default");
                }
            }
        } catch (PDOException $e) {
            // Log error but continue with default instance
            error_log("Failed to load instance ID: " . $e->getMessage());
        }
    }

    /**
     * Get host names in hostgroup
     *
     * @param string $name
     * @return array
     * @throws BackendException
     */
    public function getHostNamesInHostgroup($name) {
        $query = 'SELECT h.name
            FROM hosts h
            JOIN hosts_hostgroups hhg ON h.host_id = hhg.host_id
            JOIN hostgroups hg ON hhg.hostgroup_id = hg.hostgroup_id
            WHERE hg.name = :name AND h.enabled = 1';
        
        if ($this->_instanceId != 0) {
            $query .= ' AND h.instance_id = :instanceId';
        }

        try {
            $stmt = $this->_dbh->prepare($query);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            if ($this->_instanceId != 0) {
                $stmt->bindValue(':instanceId', $this->_instanceId, PDO::PARAM_INT);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingHostgroupMembers', [
                'BACKENDID' => $this->_backendId, 
                'ERROR' => $e->getMessage()
            ]));
        }
    }

    /**
     * Get problematic host names
     *
     * @return array
     * @throws BackendException
     */
    public function getHostNamesProblematic() {
        $problemHosts = [];
        
        // Hosts not UP
        $queryHosts = 'SELECT name FROM hosts 
            WHERE enabled = 1 AND state != 0';
        if ($this->_instanceId != 0) {
            $queryHosts .= ' AND instance_id = :instanceId';
        }
        
        try {
            $stmt = $this->_dbh->prepare($queryHosts);
            if ($this->_instanceId != 0) {
                $stmt->bindValue(':instanceId', $this->_instanceId, PDO::PARAM_INT);
            }
            $stmt->execute();
            $problemHosts = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            // Log error but continue
            error_log("Error fetching problematic hosts: " . $e->getMessage());
        }
        
        // Services not OK
        $queryServices = 'SELECT DISTINCT h.name 
            FROM services s
            JOIN hosts h ON s.host_id = h.host_id
            WHERE s.enabled = 1 AND h.enabled = 1 AND s.state != 0';
        if ($this->_instanceId != 0) {
            $queryServices .= ' AND h.instance_id = :instanceId';
        }
        
        try {
            $stmt = $this->_dbh->prepare($queryServices);
            if ($this->_instanceId != 0) {
                $stmt->bindValue(':instanceId', $this->_instanceId, PDO::PARAM_INT);
            }
            $stmt->execute();
            $problemServices = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            return array_unique(array_merge($problemHosts, $problemServices));
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingProblematicHosts', [
                'BACKENDID' => $this->_backendId, 
                'ERROR' => $e->getMessage()
            ]));
        }
    }

    /**
     * Get contacts with their groups
     *
     * @return array
     * @throws BackendException
     */
    public function getContactsWithGroups() {
        $query = 'SELECT c.contact_name, cg.cg_name
            FROM contact c
            JOIN contactgroup_contact_relation cgr ON c.contact_id = cgr.contact_id
            JOIN contactgroup cg ON cgr.contactgroup_cg_id = cg.cg_id';
        
        try {
            $stmt = $this->_dbh->query($query);
            $contacts = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $contact = $row['contact_name'];
                $group = $row['cg_name'];
                if (!isset($contacts[$contact])) {
                    $contacts[$contact] = [];
                }
                $contacts[$contact][] = $group;
            }
            return $contacts;
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingContacts', [
                'BACKENDID' => $this->_backendId, 
                'ERROR' => $e->getMessage()
            ]));
        }
    }
}
