<?php
/**
* Plugin Name: Plugin Compatibility Checker
* Description: Check Your Plugin are compatibale uptop which version of WordPress, before preforming WordPress Update
* Version: 3.0.1
* Author: Dinesh Pilani
* Author URI: https://www.linkedin.com/in/dineshpilani/
**/

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( !class_exists('PCC')){
Class PCC{
  public  function __construct() {
//Hook to add admin menu 
add_action("admin_menu", array($this,"PCC_Menu_Pages"));
    }
//Define 'UCC_Menu_Pges'
function PCC_Menu_Pages()
{
    add_menu_page( 'Plugin Compatibility Checker', 'Plugin Compatibility Checker', 'manage_options', 'PCC_Check', array(__CLASS__,'PCC_Check'), 'dashicons-screenoptions', 90);
    add_submenu_page('PCC_Check','System Info','System Info','manage_options','websiteinfo',array(__CLASS__,'Website_info_check'));
}

public static function Website_info_check(){
  
    	//Initilize Of My Custom css 
			wp_enqueue_style( 'pcctablecss.css', plugin_dir_url( __FILE__ ) . 'customcss/pcccustom.css', array(), '1.0.0' );
			
			wp_enqueue_style( 'pcctablecss.css' );
  
    echo '<h1>System Info</h1>'.'<br>';
    $CurrentPhpVersion=  phpversion();
    echo '<b style="font-size:18px;">Your Current PHP Version is : '.$CurrentPhpVersion.'</b><br>';
	
//$memory_size = memory_get_usage();
//$memory_unit = array('Bytes','KB','MB','GB','TB','PB');


$spaceBytes = disk_total_space("/");
$spacefree=disk_free_space("/");
   
$spaceGb = $spaceBytes/1024/1024/1024;
$spaceGb = (int)$spaceGb;


$spacefreeGb = $spacefree/1024/1024/1024;
$spacefreeGb = (int)$spacefreeGb;

$diskUsed=$spaceGb - $spacefreeGb;

echo '<table border =\"1\" style="border-collapse: collapse" class ="sysinfo">';
   

//echo '<tr><td><b>Memory Used By Website </b> </td><td><b>'.round($memory_size/pow(1024,($x=floor(log($memory_size,1024)))),2).' '.$memory_unit[$x]."</b></td></tr>";

echo "<tr><td><b> Disk Total Space </b></td><td><b> $spaceGb GB </b></td></tr>";

echo "<tr><td><b> Disk Space Used  </b></td><td><b> $diskUsed GB</b></td></tr>";

echo "<tr><td><b> Disk Space Free </b></td><td><b> $spacefreeGb GB</b></td></tr>";


echo '</table>';

$max_time = ini_get("max_execution_time");
$max_file_uploads=ini_get("max_file_uploads");
$max_input_vars=ini_get("max_input_vars");
$post_max_size=ini_get("post_max_size");
$memory_limit=ini_get("memory_limit");
$upload_max_filesize=ini_get("upload_max_filesize");

echo '<table border =\"1\" style="border-collapse: collapse" class ="sysinfo">';
echo "<tr><td><b> Max Execution Time  </b></td><td><b> $max_time </b></td></tr>";
echo "<tr><td><b> Max File Upload </b></td><td><b> $max_file_uploads </b></td></tr>";
echo "<tr><td><b> Max Input vars  </b></td><td><b> $max_input_vars </b></td></tr>";
echo "<tr><td><b> Post Max Size  </b></td><td><b> $post_max_size </b></td></tr>";
echo "<tr><td><b> Memory Limit  </b></td><td><b> $memory_limit </b></td></tr>";
echo "<tr><td><b> Upload Max FileSize  </b></td><td><b> $upload_max_filesize </b></td></tr>";
 

echo '</table>';
   
   
   
   
   
    $Get_Extension=get_loaded_extensions();
    echo '<center><p class="pheading">List of Extension Loaded</p>'.'<br>';
    echo '<table border =\"1\" style="border-collapse: collapse" class="extntable">';
   
    foreach($Get_Extension as $extn)
    {

        echo '<tr><td class="tdstyle">'.$extn.'</td></tr>';
    }
 
    echo '</table></center>';
   

}


//Define function
public static function PCC_Check()
{
	
				//Initilize Of My Custom css and js
			wp_enqueue_style( 'pcctablecss.css', plugin_dir_url( __FILE__ ) . 'customcss/pcccustom.css', array(), '1.0.0' );
			
			wp_enqueue_style( 'pcctablecss.css' );
	
			wp_enqueue_script( 'filtertable.js', plugin_dir_url( __FILE__ ) . 'customjs/filtertable.js', array(), '1.0.0' );
			
			wp_enqueue_script( 'filtertable.js' );
	
		
			wp_enqueue_style( 'bootstrapcss.css', plugin_dir_url( __FILE__ ) . 'customcss/bootstrap.min.css', array(), '1.0.1' );
			
			wp_enqueue_style( 'bootstrapcss.css' );
			
		//	wp_enqueue_script( 'bootstrapjs.js', plugin_dir_url( __FILE__ ) . 'customjs/bootstrap.min.js', array(), '1.0.1' );
			
			//wp_enqueue_script( 'bootstrapjs.js' );
	
	
	
	
		echo '<h1>Check Your Plugin Compatibility</h1>';
        // Get Current Version of Running WordPress
        $CurrentWPVersion= get_bloginfo( 'version' );
        $CurrentPhpVersion=  phpversion();

        

        //Get Stable Version Of WordPress
        $wordpressurl = 'https://api.wordpress.org/core/version-check/1.7/';
        $wpresponse = wp_remote_get($wordpressurl);
        if(!isset($wpresponse) || is_wp_error($wpresponse))
        {
            echo '<center><h2>Please Reload The Page Again</h2></center>';
        }
        $json = $wpresponse['body'];
        if(is_wp_error($json))
        {
            echo '<center><h2>Please Reload The Page Again</h2></center>';
        }

        $obj = json_decode($json);
        $upgrade = $obj->offers[0];
        $StableVersion=$upgrade->version;
 
        echo '<b>Your Current WordPress Version Running is : '.$CurrentWPVersion.'</b><br>';
		echo '<b>Your Current PHP Version is : '.$CurrentPhpVersion.'</b><br>';
	
        if($CurrentWPVersion == $StableVersion)
		{
			echo '<b>You Are Already Having The Lastest Version of WordPress. </b><br><br>';
     	}
		else
		{
		echo '<b>The Lastest Stable Version Of WordPress is Available is : '.$StableVersion.'</b><br><br>';
      
        }
		
        // Include StyleSheet and Initilize Table
        echo '<b>Filter By Plugin Status</b> <select class="form-control fltr" data-role="select-dropdown" id="plgstatus">
<option value="all">Plugin Status </option>
<option value="Activated">Activated	</option>
<option value="Deactivated">Deactivated</option>
</select>';
        echo '<div class="table-responsive table-hover"><table class="table table-bordered" id="pcctable">
        <thead class="thead-dark">
        <tr>
        <th scope="col">Plugin Name</th>
        <th scope="col">Current Plugin Version</th>
        <th scope="col">Lastest Plugin Version</th>
        <th scope="col">Compatible With WordPress Version</th>
        <th scope="col">Supported PHP Version</th>
        
        <th scope="col">Plugin Status</th>
        <th scope="col">Updateable With Latest Version of WordPress</th>
        <th scope="col">Issues Resolved in Last Two Months</th>
        </tr>
        </thead>';
 
        
         $plugin=get_plugins();
         $getpluginstatus=get_option('active_plugins');
         $Total_plugin =count($plugin);
        $Number_Of_plugin_activate_flag= 0;
        $Number_Of_plugin_deactivate_flag= 0;
        
echo '
<div class="tnip">
    <b>Total Number of Installed Plugin: '.$Total_plugin.' </b>
    <button id="exportButton">Export to CSV</button>
</div>
';
		  $loop = 0;
		   $storearray=array();
         foreach($plugin as $plug)
            {
         
              $array_name = array_keys($plugin)[$loop];
                $plugins_url = plugin_basename($array_name);
                $plugin_dir_path = dirname($plugins_url);
         
         
            $PluginName=$plug['Name'];
                $PluginSlug=$plug['TextDomain'];
                
              	$PluginURI=$plug['PluginURI'];
		
		 if($plugin_dir_path == '.')
		{
		 	$PluginSlug=explode("/", $PluginURI, 5);
					$PluginSlug = rtrim($PluginSlug['4'],"/");   
		}
		else if(isset($plugin_dir_path))
		{
		    $PluginSlug = $plugin_dir_path;
		}
		
				else
				{
					$PluginSlug=explode("/", $PluginURI, 5);
					$PluginSlug = rtrim($PluginSlug['4'],"/");
				}
         
			    $PluginCurrentVersion=$plug['Version'];
                $Pluginurlwithslug = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]='.$PluginSlug; 
                $PluginResponse=wp_remote_get($Pluginurlwithslug); 
                $PluginResult=$PluginResponse['body'];


                if(!isset($PluginResult) || is_wp_error($PluginResult))
                {
                    echo '<center><h2>Please Reload The Page Again</h2></center>';
                }
                $pluginobj = json_decode($PluginResult, true);
              
                if (!isset($pluginobj['error']))
                            { 
                                
                                   
                                            $TestuptoVersion=$pluginobj['tested'];
                                            $PluginLastestVerion=$pluginobj['version'];
                                       
                                        
                                            $PluginSupportThreads=$pluginobj['support_threads'];
                                             $PluginSupportThreadsResolved=$pluginobj['support_threads_resolved'];
                                      $Requirephpversion=$pluginobj['requires_php'];

                                            
                                        
                            }
                           else
                           {
                               
                                            $TestuptoVersion= 'No Data';
                                            $PluginLastestVerion='No Data';
                                       
                                        
                                            $PluginSupportThreads='No Data';
                                             $PluginSupportThreadsResolved='No Data';
                                      $Requirephpversion='No Data';
                                    
                              
                           }
                            
                          

if(!isset($Requirephpversion) || $Requirephpversion == false || $Requirephpversion == '')
{

    $Requirephpversion ='No Data';
}
if(!isset($TestuptoVersion) || $TestuptoVersion == '')
{
    $TestuptoVersion='No Data';
}

                    if($StableVersion == $TestuptoVersion)
                    {
                        $Upgradeable='Yes';
                       
                    }
                    else if($TestuptoVersion == 'No Data')
                    {
                        $Upgradeable = 'No Data';
                    }
					 else if($TestuptoVersion == '6.2.0')
                      {
                          $Upgradeable='Yes';
                          
                      }
 			else if($TestuptoVersion > $StableVersion)
			{
                          
                           $Upgradeable='Yes';
                      }
                    else
                    {
                        $Upgradeable='No';
                    }
                      
        
           if($PluginSlug){
 
   $pattern = '/' . preg_quote($PluginSlug, '/') . '/';
   if (preg_grep($pattern, $getpluginstatus))
   {

     
     $plugstats= 'Activated';
     $Number_Of_plugin_activate_flag = $Number_Of_plugin_activate_flag + 1;
      }
      else
            {
                
                $plugstats= 'Deactivated';
                $Number_Of_plugin_deactivate_flag = $Number_Of_plugin_deactivate_flag + 1;
            }
        
       }
          if(!isset($PluginLastestVerion))
          {
              $PluginLastestVerion='No Data'; 
              
          }
          
      if($PluginCurrentVersion == $PluginLastestVerion)
      {
            $trowcolor='green';
     }
      else
      {
        $trowcolor='red';
        

      }
      
        if(!isset($PluginSupportThreadsResolved))
	  {
	      $PluginSupportThreadsResolved ='No Data';
	  }
      
      if(!isset($PluginSupportThreads ))
      {
          
          $PluginSupportThreads='No Data';
      }
      
      if($PluginSupportThreadsResolved == ':0' && $PluginSupportThreads == ':0')
      {
        $numberofcases='There Are No Issues';
      }
      else
      {
      $numberofcases=$PluginSupportThreadsResolved.'/'.$PluginSupportThreads;
      }
	  
	  if($PluginSupportThreadsResolved == 'No Data' && $PluginSupportThreads == 'No Data')
	  {
	   $numberofcases ='No Data';   
	  }
	  
	  
	  
	  if(!isset($PluginSupportThreadsResolved))
	  {
	      
	      $PluginSupportThreadsResolved='No Data';
	  }
	  
	
	  
      
      $Pluginurlwithslugforversions = "https://wptide.org/api/v1/audit/wporg/plugin/$PluginSlug/$PluginLastestVerion?reports=all"; 
    
        $PluginResponseversions=wp_remote_get($Pluginurlwithslugforversions); 
      $PluginResultversions=$PluginResponseversions['body'];
      

      if(!isset($PluginResultversions) || is_wp_error($PluginResultversions))
      {
          echo '<center><h2>Please Reload The Page Again</h2></center>';
      }
      $pluginobjversion = json_decode($PluginResultversions, true);
    
   
    
      if (!isset($pluginobjversion['error']))
                  { 
                   
                   $stat=$pluginobjversion['status'];  
                     
                     
                     
                      if(isset($pluginobjversion['reports']) && isset($pluginobjversion['reports']['phpcs_phpcompatibilitywp']['report']['compatible']))
                     {
                 $compatibleVersions = $pluginobjversion['reports']['phpcs_phpcompatibilitywp']['report']['compatible'];
            
          
              
         $destination_array = '';

foreach ($compatibleVersions as $index => $value) {
    $destination_array .= $value;

    if ($index < count($compatibleVersions) - 1) {
        $destination_array .= ', ';
    }
}


}
 
 if(!isset($destination_array) || $destination_array == '' || $stat == '404' || $PluginLastestVerion == 'No Data')
             {
                 $destination_array= 'No Data';
             }


                  }

   
            


	  
	  
        echo '<tbody class="tbdy" style="background-color:'.$trowcolor.'">
        <tr>
        <th scope="row">'.$PluginName.'</th>
        <td>'.$PluginCurrentVersion.'</td>
        <td>'.$PluginLastestVerion.'</td>
        <td>'.$TestuptoVersion.'</td>
        <td>'.$destination_array.'</td>
        
        <td>'.$plugstats.'</td>
        <td>'.$Upgradeable.'</td>
        <td>'.str_replace(":","",$numberofcases).'</td>
        </tr>';
		    $loop = $loop + 1; 
		    
		 
		 
	    // Creating Array to export data to excel	 
		 $storearray[] = array(
        'Plugin Name' => str_replace(","," ",$PluginName),
        'Current Plugin Version' => $PluginCurrentVersion,
        'Lastest Plugin Version' => $PluginLastestVerion,
        'Compatible With WordPress Version' => $TestuptoVersion,
        'Supported PHP Version' => str_replace(","," ",$destination_array),
        'Plugin Status' => $plugstats,
        'Updateable With Latest Version of WordPress' => $Upgradeable,
        'Issues Resolved in Last Two Months' => "'".str_replace(":","",$numberofcases)
    );
    
    
    }
        echo '</tbody>
        </table> </div>';
  

    
echo '<b>Total Number of Activate Plugin '.': '.$Number_Of_plugin_activate_flag.'</b>'. '<br>';
echo '<b>Total Number of Deactivate Plugin '.': '.$Number_Of_plugin_deactivate_flag .'</b>'. '<br>';
         
    
echo '<br><br><b>Note:- The Plugin which are showing No Data that are not found on wordpress org as they may be Custom Plugin or licenced Plugin so please check it with the Author or from the website you have buyed, that is there lastest version avaibale for the plugin.<b><br><br><b>After Analysis Of Above Plugin Please Update the WordPress Accordingly.</b>';

?>
<script>
document.getElementById('exportButton').addEventListener('click', function() {
    // Example array data
    var dataArray = <?php echo wp_json_encode($storearray); ?>;
    
    // Convert the array data to CSV format
    var csvContent = "data:text/csv;charset=utf-8,";
    
    // Construct the header row
    var headerRow = Object.keys(dataArray[0]).join(",");
    csvContent += headerRow + "\r\n";
    
    // Iterate over each object in the array
    dataArray.forEach(function(rowObject) {
        // Construct each row of data
        var row = Object.values(rowObject).join(",");
        csvContent += row + "\r\n";
    });
    
    // Create a link element and trigger a click event to initiate download
    var encodedUri = encodeURI(csvContent);
    var link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "export.csv");
    document.body.appendChild(link); // Required for Firefox
    link.click();
});
</script>




<?php




}  
}}
new PCC();