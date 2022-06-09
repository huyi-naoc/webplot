<?php

require_once("configure.php");
define('TOTAL_POINT', 2000);
define('TIMEZONES', 28800);
define('IMGDIR', './plots/');

function current_range_checking($current)
{
    if ($current >= 4.01)
        return 0;
    else 
        return $current;
}

abstract class plotting
{
    public function __construct($plot_group, $plot_mode, $start_time, $end_time, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD, $db_name = DB_NAME)
    {

        $this->group = $plot_group;
        $this->mode = $plot_mode;
        $this->start_time = $start_time;
        $this->end_time = $end_time;
        $this->db_host = $db_host;
        $this->db_user = $db_user;
        $this->db_password = $db_password;
        $this->db_name = $db_name;

        $this->db_connect();
        $this->get_plot_info();
        $this->get_latest_time();
        $this->set_time_range();
        $this->save_memory = True;
        $this->datafile = NULL;
        
    }

    private function db_connect()
    {
        $this->mysqli = new mysqli($this->db_host, $this->db_user, $this->db_password, $this->db_name);
        if ($this->mysqli->connect_error) {
            die('Connect Error (' . mysqli_connect_errno() . ') ' . $this->mysqli->connect_error);
        }
    }

    private function get_latest_time()
    {
        $sql = "SELECT time FROM " . $this->tbl_name . " ORDER BY time DESC LIMIT 1";
        if ($results = $this->mysqli->query($sql)) {
            while ($row = $results->fetch_array(MYSQLI_ASSOC)) {
                $this->latest_time = $row['time'];
            } 
            $results->close();
        } else {
            die ('"' . $sql . '" error');
        }
    }

    private function set_time_range()
    {
        switch ($this->mode) {
            case 'day':
                $this->start_time = $this->latest_time - 24 * 3600;
                $this->end_time = $this->latest_time;
                break;
            case 'week':
                $this->start_time = $this->latest_time - 24 * 3600 * 7;
                $this->end_time = $this->latest_time;
                break;
            case 'month':
                $this->start_time = $this->latest_time - 24 * 3600 * 30;
                $this->end_time = $this->latest_time;
                break;
            case 'year':
                $this->start_time = $this->latest_time - 24 * 3600 * 365;
                $this->end_time = $this->latest_time;
                break;
            case 'range':
                $this->start_time = strtotime($this->start_time);
                $this->end_time = strtotime($this->end_time);
                break;
            default:
                die('<p>作图模式设置不正确。</p>');
                break;
        }
    }

    private function get_plot_info()
    {
        $sql = "SELECT tbl_name, data_query_comm, plot_script FROM plotinfo WHERE plot_group = '" . $this->group . "'";
        if ($results = $this->mysqli->query($sql)) {
            while ($row = $results->fetch_array(MYSQLI_ASSOC)) {
                $this->tbl_name = $row['tbl_name'];
                $this->data_query_comm = $row['data_query_comm'];
                $this->plot_script = $row['plot_script'];
            } 
            $results->close();
        } else {
            die ('"' . $sql . '" error');
        }
        if (!isset($this->tbl_name)) {
            die ('group ' . $this->group . ' does not exist in plotinfo');
        }
    }

    private function get_data_into_file()
    {

        $sql = "SELECT " . $this->data_query_comm . " FROM " . 
            $this->tbl_name . " WHERE time  BETWEEN " . 
            $this->start_time . " AND " . $this->end_time .
            " ORDER BY time";

        $results = NULL;

        if ($this->save_memory) {
            $sql2 = "SELECT count(*) FROM " . 
                $this->tbl_name . " WHERE time  BETWEEN " . 
                $this->start_time . " AND " . $this->end_time;
            $results = $this->mysqli->query($sql2);
            if (!$results) {
                die ("Query Error: " . mysqli_errno($this->mysqli));
            }
            $row = $results->fetch_array(MYSQLI_NUM);
            $n_records = $row[0];
            $results->close();
            $this->mysqli->multi_query($sql);
            $results = mysqli_use_result($this->mysqli);
            if (!$results) {
                die ("Query Error: " . mysqli_errno($this->mysqli));
            }
        } else {
            $results = $this->mysqli->query($sql);
            if (!$results) {
                die ("Query Error: " . mysqli_errno($this->mysqli));
            }
            $n_records = $results->num_rows;
        }

        if ($results != False) {
            $n_fields = $results->field_count;
            $time_str = date("dHis");
            $filepath = ABSPATH . "/tmp/" . $this->tbl_name . '_' .$time_str . ".dat";
            $fp = fopen($filepath, "w");
            if ($n_records <= TOTAL_POINT) {
                while ($row = $results->fetch_array(MYSQLI_NUM)) {
                    fprintf($fp, "%d", $row[0] + TIMEZONES);
                    for ($i = 1; $i < $n_fields; $i++)  {
                        fprintf($fp, " %f", $row[$i]);
                    }
                    fprintf($fp, "\n");
                }
            } else {
                $cnt = 0;
                $step = $n_records / TOTAL_POINT;
                $cnt_next = $step;
                while ($row = $results->fetch_array(MYSQLI_NUM)) {
                    $cnt++;
                    if ($cnt < $cnt_next) {
                        continue;
                    }
                    fprintf($fp, "%d", $row[0] + TIMEZONES);
                    for ($i = 1; $i < $n_fields; $i++)  {
                        fprintf($fp, " %f", $row[$i]);
                    }
                    fprintf($fp, "\n");
                    $cnt_next += $step;
                }
            }
            $results->close();
            fclose($fp);
            $this->datafile = $filepath;
        } else {
            $this->datafile = NULL;
        }
    }

    private function draw_plot()
    {
        $this->get_data_into_file();
        $tz_start_time = $this->start_time + TIMEZONES;
        $tz_end_time = $this->end_time + TIMEZONES;
        $plot_comm = "/usr/bin/gnuplot -e \"datafilepath='". $this->datafile . "'\" " 
                    . "-e \"start_time=". $tz_start_time . "\" "
                    . "-e \"end_time=". $tz_end_time . "\" "
                    . $this->plot_script . " 2>&1";
        $fp = popen($plot_comm, "r");
        $i = 0;
        while (!feof($fp)) {
            fscanf($fp, "%s", $this->imgfiles[$i]);
            $this->imgfiles[$i] = IMGDIR . $this->imgfiles[$i];
            $i++;
        }
        pclose($fp);
    }

    public function display_images($width)
    {
        $this->draw_plot();
        echo "<ul class=\"plots_list\">";
        foreach ($this->imgfiles as $img) {
            if (preg_match("/\.png$/", $img)) {
                echo "<li><a href=\"" . $img . "\" ><img width=\"". $width . "\" src =\"" . $img . "\"/></a></li>";
            }
        }
        echo "</ul>";
    }
    
    abstract public function display_table($mode);

    public function __destruct()
    {
        if ($this->datafile != NULL) {
            unlink($this->datafile);
        }
        $this->mysqli->close();
        unset($this->mysqli);
    }

    protected $group;
    protected $mode;
    protected $start_time;
    protected $end_time;
    protected $mysqli;
    protected $db_host;
    protected $db_user;
    protected $db_password;
    protected $db_name;
    protected $data_query_comm;
    protected $tbl_name;
    protected $plot_script;
    protected $latest_time;
    protected $datafile;
    protected $imgfiles;
    protected $save_memory;
}

class aws_plotting extends plotting
{
    public function __construct($plot_group, $plot_mode, $start_time, $end_time, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD, $db_name = DB_NAME)
    {
        parent::__construct($plot_group, $plot_mode, $start_time, $end_time, $db_host, $db_user, $db_password, $db_name);
    }

    private function get_latest_info()
    {
        $sql = "SELECT time, temp04, ws04, wd04, apin, rh02 FROM " . $this->tbl_name . " ORDER BY time DESC LIMIT 1";
        if ($results = $this->mysqli->query($sql)) {
            $row = $results->fetch_array(MYSQLI_ASSOC);
            $this->latest_ut = date("Y-m-d", $row["time"] - 28800) . "<br />" . date("H:i:s", $row["time"] - 28800);
            $this->latest_bj = date("Y-m-d", $row["time"]) . "<br />" . date("H:i:s", $row["time"]);
            $this->latest_local = date("Y-m-d", $row["time"] - 10800) . "<br />" . date("H:i:s", $row["time"] - 10800);
            $this->temperature = $row["temp04"];
            $this->wind_speed = $row["ws04"];
            $this->wind_direction = $row["wd04"];
            $this->air_pressure = $row["apin"];
            $this->humidity = $row["rh02"] * 100;
            $results->close();
        } else {

        }
    }

    public function display_table($mode)
    {
        $this->get_latest_info();
        
        switch ($mode) {
            case 'portrait':
                echo "<table>";
                echo "<tr><td>时间</td><td>" . (date("Y-m-d H:i:s", $this->latest_time)) . "</td></tr>";
                echo "<tr><td>温度</td><td>" . $this->temperature . " &#176;C</td></tr>";
                echo "<tr><td>风速</td><td>" . $this->wind_speed . " m/s</td></tr>";
                echo "<tr><td>风向</td><td>" . $this->wind_direction . " &#176;</td></tr>";
                echo "<tr><td>气压</td><td>" . $this->air_pressure . " hPa</td></tr>";
                echo "<tr><td>湿度</td><td>" . $this->humidity . " %</td></tr>";
                echo "<tr><td align=\"right\"><a href=\"http://aag.bao.ac.cn/klaws/index.php?type=weatherdata\">More...</a></td></tr>";
                echo "</table>";
                break;
            case 'landscape':
                echo "<table class=\"tabbing\">";
                echo "<thead><td>Local<br />Time</td><td>Temperature<br />(&#176;C)</td><td>Air Pressure<br />(hPa)</td><td>Wind Speed<br />(m/s)</td><td>Wind Direction <br />(&#176;)<td>Humidity<br />(%)</td><td>Beijing<br />Time</td><td>UTC</td></thead>";
                echo "<tr>";
                echo "<td>" . $this->latest_local . "</td>";
                echo "<td>" . $this->temperature . "</td>";
                echo "<td>" . $this->air_pressure . "</td>";
                echo "<td>" . $this->wind_speed . "</td>";
                echo "<td>" . $this->wind_direction . "</td>";
                echo "<td>" . $this->humidity . "</td>";
                echo "<td>" . $this->latest_bj . "</td>";
                echo "<td>" . $this->latest_ut . "</td>";
                echo "</tr>";
                echo "</table>";
                break;
            default:
                # code...
                break;
        }
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    private $latest_ut;
    private $atest_bj;
    private $latest_local;
    private $temperature;
    private $wind_speed;
    private $wind_direction;
    private $air_pressure;
}

class kldimm_position_plotting extends plotting
{
    public function __construct($plot_group, $plot_mode, $start_time, $end_time, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD, $db_name = DB_NAME)
    {
        parent::__construct($plot_group, $plot_mode, $start_time, $end_time, $db_host, $db_user, $db_password, $db_name);
    }
    
    private function get_latest_info()
    {
        $sql = "SELECT time,kldimm1_ra,kldimm1_dec,kldimm1_az,kldimm1_alt,kldimm2_ra,kldimm2_dec,kldimm2_az,kldimm2_alt FROM " . $this->tbl_name . " ORDER BY time DESC LIMIT 1";
        if ($results = $this->mysqli->query($sql)) {
            $row = $results->fetch_array(MYSQLI_ASSOC);
            $this->latest_ut = date("Y-m-d", $row["time"] - 28800) . "<br />" . date("H:i:s", $row["time"] - 28800);
            $this->latest_bj = date("Y-m-d", $row["time"]) . "<br />" . date("H:i:s", $row["time"]);
            $this->latest_local = date("Y-m-d", $row["time"] - 10800) . "<br />" . date("H:i:s", $row["time"] - 10800);

            $this->kldimm1_ra = $row['kldimm1_ra'];
            $this->kldimm1_dec = $row['kldimm1_dec'];
            $this->kldimm1_az = $row['kldimm1_az'];
            $this->kldimm1_alt = $row['kldimm1_alt'];
            $this->kldimm2_ra = $row['kldimm2_ra'];
            $this->kldimm2_dec = $row['kldimm2_dec'];
            $this->kldimm2_az = $row['kldimm2_az'];
            $this->kldimm2_alt = $row['kldimm2_alt'];

            $results->close();
        } else {

        }
    }

    public function display_table($mode)
    {
        
        $this->get_latest_info();
        switch ($mode) {
            case 'portrait':
                break;
            case 'landscape':
                echo "<table class=\"tabbing\">";
                echo "<thead><td>Local<br />Time</td><td>KLDIMM1 RA<br />(&#176;)</td><td>KLDIMM1 DEC<br />(&#176;)</td><td>KLDIMM2 RA<br />(&#176;)</td><td>KLDIMM2 DEC<br />(&#176;)</td><td>Beijing<br />Time</td><td>UTC</td></thead>";
                echo "<tr>";
                echo "<td>" . $this->latest_local . "</td>";
                echo "<td>" . $this->kldimm1_ra . "</td>";
                echo "<td>" . $this->kldimm1_dec . "</td>";
                echo "<td>" . $this->kldimm2_ra . "</td>";
                echo "<td>" . $this->kldimm2_dec . "</td>";
                echo "<td>" . $this->latest_bj . "</td>";
                echo "<td>" . $this->latest_ut . "</td>";
                echo "</tr>";
                echo "</table>";
                break;
            default:
                # code...
                break;
        }
        
        return;
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    private $latest_ut;
    private $latest_bj;
    private $latest_local;
    private $kldimm1_ra;
    private $kldimm1_dec;
    private $kldimm1_az;
    private $kldimm1_alt;
    private $kldimm2_ra;
    private $kldimm2_dec;
    private $kldimm2_az;
    private $kldimm2_alt;
}

class kldimm1_status_plotting extends plotting
{
    public function __construct($plot_group, $plot_mode, $start_time, $end_time, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD, $db_name = DB_NAME)
    {
        parent::__construct($plot_group, $plot_mode, $start_time, $end_time, $db_host, $db_user, $db_password, $db_name);
    }
    
    private function get_latest_info()
    {
        $sql = "SELECT time,decenc,raenc,ccdmirror1,raout_v,raout_i,decin_v,decin_i,ragear_v,ragear_i,decgear_v,decgear_i,raenc_v,raenc_i,decenc_v,decenc_i,ccdheater_v,ccdheater_i,guideheater_v,guideheater_i,ccdito1_v,ccdito1_i,ccdito2_v,ccdito2_i,guideito_v,guideito_i,ccd_v,ccd_i,guide_v,guide_i FROM " . $this->tbl_name . " ORDER BY time DESC LIMIT 1";
        if ($results = $this->mysqli->query($sql)) {
            $row = $results->fetch_array(MYSQLI_ASSOC);
            $this->latest_ut = date("Y-m-d", $row["time"] - 28800) . "<br />" . date("H:i:s", $row["time"] - 28800);
            $this->latest_bj = date("Y-m-d", $row["time"]) . "<br />" . date("H:i:s", $row["time"]);
            $this->latest_local = date("Y-m-d", $row["time"] - 10800) . "<br />" . date("H:i:s", $row["time"] - 10800);

            $this->raenc_temperature = $row['raenc'];
            $this->decenc_temperature = $row['decenc'];
            $this->mirror_temperature = $row['ccdmirror1'];

            $this->power = 0.;

            $voltage = $row["raout_v"];
            $current = current_range_checking($row["raout_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["decin_v"];
            $current = current_range_checking($row["decin_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["ragear_v"];
            $current = current_range_checking($row["ragear_i"]);
            $this->power += $voltage * $current;     
            $voltage = $row["decgear_v"];
            $current = current_range_checking($row["decgear_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["ccd_v"];
            $current = current_range_checking($row["ccd_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["guide_v"];
            $current = current_range_checking($row["guide_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["ccdito1_v"];
            $current = current_range_checking($row["ccdito1_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["ccdito2_v"];
            $current = current_range_checking($row["ccdito2_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["guideito_v"];
            $current = current_range_checking($row["guideito_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["raenc_v"];
            $current = current_range_checking($row["raenc_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["decenc_v"];
            $current = current_range_checking($row["decenc_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["ccdheater_v"];
            $current = current_range_checking($row["ccdheater_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["guideheater_v"];
            $current = current_range_checking($row["guideheater_i"]);
            $this->power += $voltage * $current;
            

            $results->close();
        } else {

        }
    }

    public function display_table($mode)
    {
        
        $this->get_latest_info();
        switch ($mode) {
            case 'portrait':
                break;
            case 'landscape':
                echo "<table class=\"tabbing\">";
                echo "<thead><td>Local<br />Time</td><td>RA Encoder <br />Temperature<br />(&#176;C)</td><td>DEC Encoder <br />Temperature<br />(&#176;C)</td><td>Mirror Temperature<br />(&#176;C)</td><td>Power<br />(W)</td><td>Beijing<br />Time</td><td>UTC</td></thead>";
                echo "<tr>";
                echo "<td>" . $this->latest_local . "</td>";
                echo "<td>" . $this->raenc_temperature . "</td>";
                echo "<td>" . $this->decenc_temperature . "</td>";
                echo "<td>" . $this->mirror_temperature . "</td>";
                echo "<td>" . $this->power . "</td>";
                echo "<td>" . $this->latest_bj . "</td>";
                echo "<td>" . $this->latest_ut . "</td>";
                echo "</tr>";
                echo "</table>";
                break;
            default:
                # code...
                break;
        }
        
        return;
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    private $latest_ut;
    private $atest_bj;
    private $latest_local;
    private $raenc_temperature;
    private $decenc_temperature;
    private $mirror_temperature;
    private $power;
}

class kldimm2_status_plotting extends plotting
{
    public function __construct($plot_group, $plot_mode, $start_time, $end_time, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD, $db_name = DB_NAME)
    {
        parent::__construct($plot_group, $plot_mode, $start_time, $end_time, $db_host, $db_user, $db_password, $db_name);
    }
    
    private function get_latest_info()
    {
        $sql = "SELECT time,decenc,raenc,ccdmirror1,ccd_v,ccd_i,guide_v,guide_i,ccdito_v,ccdito_i,guideito_v,guideito_i,raenc_v,raenc_i,decenc_v,decenc_i,ccdheater_v,ccdheater_i,guideheater_v,guideheater_i FROM " . $this->tbl_name . " ORDER BY time DESC LIMIT 1";
        if ($results = $this->mysqli->query($sql)) {
            $row = $results->fetch_array(MYSQLI_ASSOC);
            $this->latest_ut = date("Y-m-d", $row["time"] - 28800) . "<br />" . date("H:i:s", $row["time"] - 28800);
            $this->latest_bj = date("Y-m-d", $row["time"]) . "<br />" . date("H:i:s", $row["time"]);
            $this->latest_local = date("Y-m-d", $row["time"] - 10800) . "<br />" . date("H:i:s", $row["time"] - 10800);

            $this->raenc_temperature = $row['raenc'];
            $this->decenc_temperature = $row['decenc'];
            $this->mirror_temperature = $row['ccdmirror1'];

            $this->power = 0.;
            $voltage = $row["ccd_v"];
            $current = current_range_checking($row["ccd_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["guide_v"];
            $current = current_range_checking($row["guide_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["ccdito_v"];
            $current = current_range_checking($row["ccdito_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["guideito_v"];
            $current = current_range_checking($row["guideito_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["raenc_v"];
            $current = current_range_checking($row["raenc_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["decenc_v"];
            $current = current_range_checking($row["decenc_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["ccdheater_v"];
            $current = current_range_checking($row["ccdheater_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["guideheater_v"];
            $current = current_range_checking($row["guideheater_i"]);
            $this->power += $voltage * $current;
            

            $results->close();
        } else {

        }
    }

    public function display_table($mode)
    {
        
        $this->get_latest_info();
        switch ($mode) {
            case 'portrait':
                break;
            case 'landscape':
                echo "<table class=\"tabbing\">";
                echo "<thead><td>Local<br />Time</td><td>RA Encoder <br />Temperature<br />(&#176;C)</td><td>DEC Encoder <br />Temperature<br />(&#176;C)</td><td>Mirror Temperature<br />(&#176;C)</td><td>Power<br />(W)</td><td>Beijing<br />Time</td><td>UTC</td></thead>";
                echo "<tr>";
                echo "<td>" . $this->latest_local . "</td>";
                echo "<td>" . $this->raenc_temperature . "</td>";
                echo "<td>" . $this->decenc_temperature . "</td>";
                echo "<td>" . $this->mirror_temperature . "</td>";
                echo "<td>" . $this->power . "</td>";
                echo "<td>" . $this->latest_bj . "</td>";
                echo "<td>" . $this->latest_ut . "</td>";
                echo "</tr>";
                echo "</table>";
                break;
            default:
                # code...
                break;
        }
        
        return;
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    private $latest_ut;
    private $atest_bj;
    private $latest_local;
    private $raenc_temperature;
    private $decenc_temperature;
    private $mirror_temperature;
    private $power;
}

class kldimmcb_status_plotting extends plotting
{
    public function __construct($plot_group, $plot_mode, $start_time, $end_time, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD, $db_name = DB_NAME)
    {
        parent::__construct($plot_group, $plot_mode, $start_time, $end_time, $db_host, $db_user, $db_password, $db_name);
    }
    
    private function get_latest_info()
    {
        $sql = "SELECT time,temp1,temp2,cbheater1_v,cbheater1_i,cbheater2_v,cbheater2_i,cbheater3_v,cbheater3_i,cbheater4_v,cbheater4_i,webcam1_v,webcam1_i,webcam1heater_v,webcam1heater_i,webcam2_v,webcam2_i,webcam2heater_v,webcam2heater_i,webcamled_v,webcamled_i FROM " . $this->tbl_name . " ORDER BY time DESC LIMIT 1";
        if ($results = $this->mysqli->query($sql)) {
            $row = $results->fetch_array(MYSQLI_ASSOC);
            $this->latest_ut = date("Y-m-d", $row["time"] - 28800) . "<br />" . date("H:i:s", $row["time"] - 28800);
            $this->latest_bj = date("Y-m-d", $row["time"]) . "<br />" . date("H:i:s", $row["time"]);
            $this->latest_local = date("Y-m-d", $row["time"] - 10800) . "<br />" . date("H:i:s", $row["time"] - 10800);

            $this->temperature1 = $row['temp1'];
            $this->temperature2= $row['temp2'];

            $this->power = 0.;

            $voltage = $row["cbheater1_v"];
            $current = current_range_checking($row["cbheater1_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["cbheater2_v"];
            $current = current_range_checking($row["cbheater2_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["cbheater3_v"];
            $current = current_range_checking($row["cbheater3_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["cbheater4_v"];
            $current = current_range_checking($row["cbheater4_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["webcam1_v"];
            $current = current_range_checking($row["webcam1_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["webcam1heater_v"];
            $current = current_range_checking($row["webcam1heater_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["webcam2_v"];
            $current = current_range_checking($row["webcam2_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["webcam2heater_v"];
            $current = current_range_checking($row["webcam2heater_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["webcamled_v"];
            $current = current_range_checking($row["webcamled_i"]);
            $this->power += $voltage * $current;

            $results->close();
        } else {

        }
    }

    public function display_table($mode)
    {
        
        $this->get_latest_info();
        switch ($mode) {
            case 'portrait':
                break;
            case 'landscape':
                echo "<table class=\"tabbing\">";
                echo "<thead><td>Local<br />Time</td><td>Control Box<br />Temperature1<br />(&#176;C)</td><td>Control Box<br />Temperature2<br />(&#176;C)</td><td>Power<br />(W)</td><td>Beijing<br />Time</td><td>UTC</td></thead>";
                echo "<tr>";
                echo "<td>" . $this->latest_local . "</td>";
                echo "<td>" . $this->temperature1 . "</td>";
                echo "<td>" . $this->temperature2 . "</td>";
                echo "<td>" . $this->power . "</td>";
                echo "<td>" . $this->latest_bj . "</td>";
                echo "<td>" . $this->latest_ut . "</td>";
                echo "</tr>";
                echo "</table>";
                break;
            default:
                # code...
                break;
        }
        
        return;
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    private $latest_ut;
    private $atest_bj;
    private $latest_local;
    private $temperature1;
    private $temperature2;
    private $power;
}


class site_test_status_plotting extends plotting
{
    public function __construct($plot_group, $plot_mode, $start_time, $end_time, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD, $db_name = DB_NAME)
    {
        parent::__construct($plot_group, $plot_mode, $start_time, $end_time, $db_host, $db_user, $db_password, $db_name);
    }
    
    private function get_latest_info()
    {
        $sql = "SELECT time,dimmcomputer1,dimmcomputer2,dimmcontrolbox,klcam2out,klcam2in,klcam3out,klcam3in,dimmcomputer1_v,dimmcomputer1_i,dimmcomputer1disk_v,dimmcomputer1disk_i,dimmcomputer2_v,dimmcomputer2_i,dimmcomputer2disk_v,dimmcomputer2disk_i,dimmcontrolbox1_v,dimmcontrolbox1_i,dimmcontrolbox2_v,dimmcontrolbox2_i,dimmcontrolboxheater1_v,dimmcontrolboxheater1_i,dimmcontrolboxheater2_v,dimmcontrolboxheater2_i,klcam2_v,klcam2_i,klcam2heater_v,klcam2heater_i,klcam3_v,klcam3_i,klcam3heater_v,klcam3heater_i,klawsacq_v,klawsacq_i,klawsrs1_v,klawsrs1_i,klawsrs2_v,klawsrs2_i,klawsheater_v,klawsheater_i FROM " . $this->tbl_name . " ORDER BY time DESC LIMIT 1";
        if ($results = $this->mysqli->query($sql)) {
            $row = $results->fetch_array(MYSQLI_ASSOC);
            $this->latest_ut = date("Y-m-d", $row["time"] - 28800) . "<br />" . date("H:i:s", $row["time"] - 28800);
            $this->latest_bj = date("Y-m-d", $row["time"]) . "<br />" . date("H:i:s", $row["time"]);
            $this->latest_local = date("Y-m-d", $row["time"] - 10800) . "<br />" . date("H:i:s", $row["time"] - 10800);

            $this->controlbox_temperature = $row["dimmcontrolbox"];
            $this->klcam2out_temperature = $row["klcam2out"];
            $this->klcam3out_temperature = $row["klcam3out"];

            $this->klcam2_power = 0.;
            $voltage = $row["klcam2_v"];
            $current = current_range_checking($row["klcam2_i"]);
            $this->klcam2_power += $voltage * $current;
            $voltage = $row["klcam2heater_v"];
            $current = current_range_checking($row["klcam2heater_i"]);
            $this->klcam2_power += $voltage * $current;

            $this->klcam3_power = 0.;
            $voltage = $row["klcam3_v"];
            $current = current_range_checking($row["klcam3_i"]);
            $this->klcam3_power += $voltage * $current;
            $voltage = $row["klcam3heater_v"];
            $current = current_range_checking($row["klcam3heater_i"]);
            $this->klcam3_power += $voltage * $current;

            $this->kldimm_power = 0.;
            $voltage = $row["dimmcontrolbox1_v"];
            $current = current_range_checking($row["dimmcontrolbox1_i"]);
            $this->kldimm_power += $voltage * $current;
            $voltage = $row["dimmcontrolbox2_v"];
            $current = current_range_checking($row["dimmcontrolbox2_i"]);
            $this->kldimm_power += $voltage * $current;
            $voltage = $row["dimmcontrolboxheater1_v"];
            $current = current_range_checking($row["dimmcontrolboxheater1_i"]);
            $this->kldimm_power += $voltage * $current;
            $voltage = $row["dimmcontrolboxheater2_v"];
            $current = current_range_checking($row["dimmcontrolboxheater2_i"]);
            $this->kldimm_power += $voltage * $current;

            $this->klaws_power = 0.;
            $voltage = $row["klawsacq_v"];
            $current = current_range_checking($row["klawsacq_i"]);
            $this->klaws_power += $voltage * $current;
            $voltage = $row["klawsrs1_v"];
            $current = current_range_checking($row["klawsrs1_i"]);
            $this->klaws_power += $voltage * $current;
            $voltage = $row["klawsrs2_v"];
            $current = current_range_checking($row["klawsrs2_i"]);
            $this->klaws_power += $voltage * $current;
            $voltage = $row["klawsheater_v"];
            $current = current_range_checking($row["klawsheater_i"]);
            $this->klaws_power += $voltage * $current;

            $results->close();
        } else {

        }
    }

    public function display_table($mode)
    {
        
        $this->get_latest_info();
        switch ($mode) {
            case 'portrait':
                break;
            case 'landscape':
                echo "<table class=\"tabbing\">";
                echo "<thead><td>Local<br />Time</td><td>Controlbox Temperature<br />(&#176;C)</td><td>KLCAM2 Temperature<br />(&#176;C)</td><td>KLCAM3 Temperature<br />(&#176;C)</td><td>KLCAM2 Power<br />(W)</td><td>KLCAM23 Power<br />(W)</td><td>KLDIMM Power<br />(W)</td><td>KLAWS Power<br />(W)</td><td>Beijing<br />Time</td><td>UTC</td></thead>";
                echo "<tr>";
                echo "<td>" . $this->latest_local . "</td>";
                echo "<td>" . $this->controlbox_temperature . "</td>";
                echo "<td>" . $this->klcam2out_temperature . "</td>";
                echo "<td>" . $this->klcam3out_temperature . "</td>";
                echo "<td>" . $this->klcam2_power . "</td>";
                echo "<td>" . $this->klcam3_power . "</td>";
                echo "<td>" . $this->kldimm_power . "</td>";
                echo "<td>" . $this->klaws_power . "</td>";
                echo "<td>" . $this->latest_bj . "</td>";
                echo "<td>" . $this->latest_ut . "</td>";
                echo "</tr>";
                echo "</table>";
                break;
            default:
                # code...
                break;
        }
        
        return;
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    private $latest_ut;
    private $atest_bj;
    private $latest_local;
    private $controlbox_temperature;
    private $klcam2out_temperature;
    private $klcam3out_temperature;
    private $klcam2_power;
    private $klcam3_power;
    private $klaws_power;
    private $kldimm_power;

}

class ast3_ccd_plotting extends plotting
{
    public function __construct($plot_group, $plot_mode, $start_time, $end_time, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD, $db_name = DB_NAME)
    {
        parent::__construct($plot_group, $plot_mode, $start_time, $end_time, $db_host, $db_user, $db_password, $db_name);
    }

    private function get_latest_info()
    {
        
        $sql = "SELECT time, tout,tin, FROM " . $this->tbl_name . " ORDER BY time DESC LIMIT 1";
        if ($results = $this->mysqli->query($sql)) {
            $row = $results->fetch_array(MYSQLI_ASSOC);
            $this->latest_ut = date("Y-m-d", $row["time"] - 28800) . "<br />" . date("H:i:s", $row["time"] - 28800);
            $this->latest_bj = date("Y-m-d", $row["time"]) . "<br />" . date("H:i:s", $row["time"]);
            $this->latest_local = date("Y-m-d", $row["time"] - 10800) . "<br />" . date("H:i:s", $row["time"] - 10800);
            $this->setting_temperature = $row["settemp"];
            $this->actual_temperature = $row["ccdtemp"];
            $results->close();
        } else {

        }
        
    }

    public function display_table($mode)
    {
        $this->get_latest_info();
        switch ($mode) {
            case 'portrait':
                break;
            case 'landscape':
                echo "<table class=\"tabbing\">";
                echo "<thead><td>Local<br />Time</td><td>Setting Temperature<br />(&#176;C)</td><td>Actual Temperature<br />(&#176;C)</td><td>Beijing<br />Time</td><td>UTC</td></thead>";
                echo "<tr>";
                echo "<td>" . $this->latest_local . "</td>";
                echo "<td>" . $this->setting_temperature . "</td>";
                echo "<td>" . $this->actual_temperature . "</td>";
                echo "<td>" . $this->latest_bj . "</td>";
                echo "<td>" . $this->latest_ut . "</td>";
                echo "</tr>";
                echo "</table>";
                break;
            default:
                # code...
                break;
        }
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    private $latest_ut;
    private $atest_bj;
    private $latest_local;
    private $setting_temperature;
    private $actual_temperature;
}

class pdusds45_status_plotting extends plotting
{
    public function __construct($plot_group, $plot_mode, $start_time, $end_time, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD, $db_name = DB_NAME)
    {
        parent::__construct($plot_group, $plot_mode, $start_time, $end_time, $db_host, $db_user, $db_password, $db_name);
    }

    private function get_latest_info()
    {
        
        $sql = "SELECT time,tout,tin,p09computer_v,p09computer_i,p09disk_v,p09disk_i,p10computer_v,p10computer_i,p10disk_v,p10disk_i,p05computer_v,p05computer_i,p05disk_v,p05disk_i,p06computer_v,p06computer_i,p06disk_v,p06disk_i,a09computer_v,a09computer_i,a09disk_v,a09disk_i,a10computer_v,a10computer_i,a10disk_v,a10disk_i FROM " . $this->tbl_name . " ORDER BY time DESC LIMIT 1";
        if ($results = $this->mysqli->query($sql)) {
            $row = $results->fetch_array(MYSQLI_ASSOC);
            $this->latest_ut = date("Y-m-d", $row["time"] - 28800) . "<br />" . date("H:i:s", $row["time"] - 28800);
            $this->latest_bj = date("Y-m-d", $row["time"]) . "<br />" . date("H:i:s", $row["time"]);
            $this->latest_local = date("Y-m-d", $row["time"] - 10800) . "<br />" . date("H:i:s", $row["time"] - 10800);
            $this->tin = $row["tout"];
            $this->tout = $row["tin"];

            $this->power = 0.;
            $voltage = $row["p09computer_v"];
            $current = current_range_checking($row["p09computer_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["p10computer_v"];
            $current = current_range_checking($row["p10computer_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["p05computer_v"];
            $current = current_range_checking($row["p05computer_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["p06computer_v"];
            $current = current_range_checking($row["p06computer_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["p09disk_v"];
            $current = current_range_checking($row["p09disk_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["p10disk_v"];
            $current = current_range_checking($row["p10disk_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["p05disk_v"];
            $current = current_range_checking($row["p05disk_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["p06disk_v"];
            $current = current_range_checking($row["p06disk_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["a09computer_v"];
            $current = current_range_checking($row["a09computer_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["a10computer_v"];
            $current = current_range_checking($row["a10computer_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["a09disk_v"];
            $current = current_range_checking($row["a09computer_i"]);
            $this->power += $voltage * $current;
            $voltage = $row["a10disk_v"];
            $current = current_range_checking($row["a10computer_i"]);
            $this->power += $voltage * $current;
            
            $results->close();
        } else {

        }
        
    }

    public function display_table($mode)
    {
        $this->get_latest_info();
        switch ($mode) {
            case 'portrait':
                break;
            case 'landscape':
                echo "<table class=\"tabbing\">";
                echo "<thead><td>Local<br />Time</td><td>IM INNER Temperature<br />(&#176;C)</td><td>IM OUTER Temperature<br />(&#176;C)</td><td>Total Power<br />(W)</td><td>Beijing<br />Time</td><td>UTC</td></thead>";
                echo "<tr>";
                echo "<td>" . $this->latest_local . "</td>";
                echo "<td>" . $this->tout . "</td>";
                echo "<td>" . $this->tin . "</td>";
                echo "<td>" . $this->power . "</td>";
                echo "<td>" . $this->latest_bj . "</td>";
                echo "<td>" . $this->latest_ut . "</td>";
                echo "</tr>";
                echo "</table>";
                break;
            default:
                # code...
                break;
        }
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    private $latest_ut;
    private $atest_bj;
    private $latest_local;
    private $tout;
    private $tin;
    private $power;
}

class kldimm_seeing_plotting extends plotting
{
    public function __construct($plot_group, $plot_mode, $start_time, $end_time, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD, $db_name = DB_NAME)
    {
        parent::__construct($plot_group, $plot_mode, $start_time, $end_time, $db_host, $db_user, $db_password, $db_name);
    }
    
    private function get_latest_info()
    {
        $sql = "SELECT time,seeing_zenith_s,seeing_raw_s FROM " . $this->tbl_name . " ORDER BY time DESC LIMIT 1";
        if ($results = $this->mysqli->query($sql)) {
            $row = $results->fetch_array(MYSQLI_ASSOC);
            $this->latest_ut = date("Y-m-d", $row["time"] - 28800) . "<br />" . date("H:i:s", $row["time"] - 28800);
            $this->latest_bj = date("Y-m-d", $row["time"]) . "<br />" . date("H:i:s", $row["time"]);
            $this->latest_local = date("Y-m-d", $row["time"] - 10800) . "<br />" . date("H:i:s", $row["time"] - 10800);

            $this->seeing_zenith = $row['seeing_zenith_s'];
            $this->seeing_raw = $row['seeing_raw_s'];

            $results->close();
        } else {

        }
    }

    public function display_table($mode)
    {
        $this->get_latest_info();
        switch ($mode) {
            case 'portrait':
                break;
            case 'landscape':
                echo "<table class=\"tabbing\">";
                echo "<thead><td>Local<br />Time</td><td>Seeing<br />(&#8243;)</td><td>Raw seeing<br />(&#8243;)</td><td>Beijing<br />Time</td><td>UTC</td></thead>";
                echo "<tr>";
                echo "<td>" . $this->latest_local . "</td>";
                echo "<td>" . $this->seeing_zenith . "</td>";
                echo "<td>" . $this->seeing_raw . "</td>";
                echo "<td>" . $this->latest_bj . "</td>";
                echo "<td>" . $this->latest_ut . "</td>";
                echo "</tr>";
                echo "</table>";
                break;
            default:
                # code...
                break;
        }
        
        return;
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    private $latest_ut;
    private $latest_bj;
    private $latest_local;
    private $seeing_zenith;
    private $seeing_raw;
}

class kldimm_flux_plotting extends plotting
{
    public function __construct($plot_group, $plot_mode, $start_time, $end_time, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD, $db_name = DB_NAME)
    {
        parent::__construct($plot_group, $plot_mode, $start_time, $end_time, $db_host, $db_user, $db_password, $db_name);
    }
    
    private function get_latest_info()
    {
        $sql = "SELECT time,flux_1,flux_2 FROM " . $this->tbl_name . " ORDER BY time DESC LIMIT 1";
        if ($results = $this->mysqli->query($sql)) {
            $row = $results->fetch_array(MYSQLI_ASSOC);
            $this->latest_ut = date("Y-m-d", $row["time"] - 28800) . "<br />" . date("H:i:s", $row["time"] - 28800);
            $this->latest_bj = date("Y-m-d", $row["time"]) . "<br />" . date("H:i:s", $row["time"]);
            $this->latest_local = date("Y-m-d", $row["time"] - 10800) . "<br />" . date("H:i:s", $row["time"] - 10800);

            $this->flux_1 = $row['flux_1'];
            $this->flux_2 = $row['flux_2'];

            $results->close();
        } else {

        }
    }

    public function display_table($mode)
    {
        $this->get_latest_info();
        switch ($mode) {
            case 'portrait':
                break;
            case 'landscape':
                echo "<table class=\"tabbing\">";
                echo "<thead><td>Local<br />Time</td><td>Flux 1<br />(ADU)</td><td>FLux 2<br />(ADU)</td><td>Beijing<br />Time</td><td>UTC</td></thead>";
                echo "<tr>";
                echo "<td>" . $this->latest_local . "</td>";
                echo "<td>" . $this->flux_1 . "</td>";
                echo "<td>" . $this->flux_2 . "</td>";
                echo "<td>" . $this->latest_bj . "</td>";
                echo "<td>" . $this->latest_ut . "</td>";
                echo "</tr>";
                echo "</table>";
                break;
            default:
                # code...
                break;
        }
        
        return;
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    private $latest_ut;
    private $latest_bj;
    private $latest_local;
    private $flux_1;
    private $flux_2;
}

class lhaws_plotting extends plotting
{
    public function __construct($plot_group, $plot_mode, $start_time, $end_time, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD, $db_name = DB_NAME)
    {
        parent::__construct($plot_group, $plot_mode, $start_time, $end_time, $db_host, $db_user, $db_password, $db_name);
    }

    private function get_latest_info()
    {
        $sql = "SELECT time, temp, rh, ap, ws, wd, prec, flux FROM " . $this->tbl_name . " ORDER BY time DESC LIMIT 1";
        if ($results = $this->mysqli->query($sql)) {
            $row = $results->fetch_array(MYSQLI_ASSOC);
            $this->latest_ut = date("Y-m-d", $row["time"] - 28800) . "<br />" . date("H:i:s", $row["time"] - 28800);
            $this->latest_bj = date("Y-m-d", $row["time"]) . "<br />" . date("H:i:s", $row["time"]);
            $this->latest_local = date("Y-m-d", $row["time"] - 10800) . "<br />" . date("H:i:s", $row["time"] - 10800);
            $this->temperature = $row["temp"];
            $this->wind_speed = $row["ws"];
            $this->wind_direction = $row["wd"];
            $this->air_pressure = $row["ap"];
        $this->humidity = $row["rh"];
        $this->precipitation = $row["prec"];
        $this->flux = $row["flux"];
            $results->close();
        } else {

        }
    }

    public function display_table($mode)
    {
        $this->get_latest_info();
        
        switch ($mode) {
            case 'portrait':
                echo "<table>";
                echo "<tr><td>时间</td><td>" . (date("Y-m-d H:i:s", $this->latest_time)) . "</td></tr>";
                echo "<tr><td>温度</td><td>" . $this->temperature . " &#176;C</td></tr>";
                echo "<tr><td>风速</td><td>" . $this->wind_speed . " m/s</td></tr>";
                echo "<tr><td>风向</td><td>" . $this->wind_direction . " &#176;</td></tr>";
                echo "<tr><td>气压</td><td>" . $this->air_pressure . " hPa</td></tr>";
                echo "<tr><td>湿度</td><td>" . $this->humidity . " %</td></tr>";
                echo "<tr><td>降水</td><td>" . $this->precipitation . " mm</td></tr>";
                echo "<tr><td>辐射</td><td>" . $this->flux . " kLux</td></tr>";
                echo "<tr><td align=\"right\"><a href=\"http://aag.bao.ac.cn/sitian/index.php?type=weatherdata\">More...</a></td></tr>";
                echo "</table>";
                break;
            case 'landscape':
                echo "<table class=\"tabbing\">";
                echo "<thead><td>Beijing Time</td><td>Temperature<br />(&#176;C)</td><td>Air Pressure<br />(hPa)</td><td>Wind Speed<br />(m/s)</td><td>Wind Direction <br />(&#176;)<td>Humidity<br />(%)</td><td>Precipitation <br />(mm)</td><td>Illuminance <br />(kLux)</td></thead>";
                echo "<tr>";
                echo "<td>" . $this->latest_bj . "</td>";
                echo "<td>" . $this->temperature . "</td>";
                echo "<td>" . $this->air_pressure . "</td>";
                echo "<td>" . $this->wind_speed . "</td>";
                echo "<td>" . $this->wind_direction . "</td>";
        echo "<td>" . $this->humidity . "</td>";
        echo "<td>" . $this->precipitation . "</td>";
        echo "<td>" . $this->flux . "</td>";
                echo "</tr>";
                echo "</table>";
                break;
            default:
                # code...
                break;
        }
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    private $latest_ut;
    private $atest_bj;
    private $latest_local;
    private $temperature;
    private $wind_speed;
    private $wind_direction;
    private $air_pressure;
    private $humidity;
    private $precipitation;
    private $flux;
}
?>
