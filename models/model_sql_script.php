<?php

namespace adapt\sql_install;

use adapt\model;
use adapt\sql;
use adapt\sql_install_mysql\bundle_sql_install_mysql;

defined('ADAPT_STARTED') or die;

class model_sql_script extends model
{
    /**
     * model_sql_script constructor.
     * @param null $id
     * @param null $data_source
     */
    public function __construct($id = null, $data_source = null)
    {
        parent::__construct('sql_script', $id, $data_source);
    }

    /**
     * Loads a record based on several parameters
     * @param string $bundle_name
     * @param string $file_name
     * @param string $dialect
     * @return boolean
     */
    public function load_by_bundle_file($bundle_name, $file_name, $dialect)
    {
        // Sanity check
        if (!$bundle_name || !$file_name || !$dialect) {
            return false;
        }

        // Attempt to pull the data
        $sql = $this->data_source->sql;
        $sql->select('ss.*')
            ->from('sql_script', 'ss')
            ->join('bundle_version', 'bv', 'bundle_version_id')
            ->where(
                new sql_and(
                    new sql_cond('ss.date_deleted', sql::IS, new sql_null()),
                    new sql_cond('bv.date_deleted', sql::IS, new sql_null()),
                    new sql_cond('ss.script_file_name', sql::EQUALS, q($file_name)),
                    new sql_cond('bv.bundle_name', sql::EQUALS, q($bundle_name))
                )
            );

        $results = $sql->execute(0)->results();

        if (count($results) != 1) {
            return false;
        }

        return $this->load_by_data($results[0]);
    }

    /**
     * Indicates whether it is safe to run a script
     * @param string $bundle_name
     * @param string $file_path
     * @param string $dialect
     * @return boolean
     */
    public function safe_to_run($bundle_name, $file_path, $dialect)
    {
        // Sanity check
        if (!$bundle_name || !$file_path || !$dialect) {
            return false;
        }

        // Check file exists
        if (!file_exists($file_path)) {
            return false;
        }

        // Get file name from path
        $file_name = array_reverse(explode(DIRECTORY_SEPARATOR, $file_path))[0];

        // Test the load
        if ($this->load_by_bundle_file($bundle_name, $file_name, $dialect)) {
            // We have a record - compare the hash
            $md5 = md5_file($file_path);
            if ($md5 == $this->script_content_md5) {
                // File is the same as the previous run - no need to re-run
                return false;
            } else {
                // File is different - needs to be run
                return true;
            }
        }

        return true;
    }

    /**
     * @param string $bundle_name
     * @param string $bundle_version
     * @param string $file_path
     * @param string $dialect
     */
    public function ran_script($bundle_name, $bundle_version, $file_path, $dialect)
    {
        // Sanity check
        if (!$bundle_name || !$bundle_version || !$file_path || !$dialect) {
            return;
        }

        // Find the new bundle to record against
        $sql = $this->data_source->sql;
        $sql->select('bundle_version_id as id')
            ->from('bundle_version')
            ->where(
                new sql_and(
                    new sql_cond('date_deleted', sql::IS, new sql_null()),
                    new sql_cond('bundle_name', sql::EQUALS, q($bundle_name)),
                    new sql_cond('version', sql::EQUALS, q($bundle_version))
                )
            );

        $results = $sql->execute(0)->results();

        if (count($results) != 1) {
            return;
        }

        // Generate the new record
        $file_name = array_reverse(explode(DIRECTORY_SEPARATOR, $file_path))[0];
        $md5 = md5_file($file_path);
        $sql_script = new model_sql_script();
        $sql_script->script_file_name = $file_name;
        $sql_script->script_content_md5 = $md5;
        $sql_script->bundle_version_id = $results[0]['id'];
        $sql_script->dialect = $dialect;

        if (!$sql_script->save()) {
            $this->error($sql_script->errors(true));
            return;
        }

        // If we already have a record, delete it
        if ($this->is_loaded) {
            $this->delete();
        }
    }
}