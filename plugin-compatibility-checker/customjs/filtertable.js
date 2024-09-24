jQuery(document).ready(function () {
        jQuery("#plgstatus").on("change", function () {
         
            var plugdata = jQuery('#plgstatus').find("option:selected").val();
            SearchData(plugdata)
        });
    });
    function SearchData(plugdata) {
        if (plugdata.toUpperCase() == 'ALL') {
            jQuery('#pcctable tbody tr').show();
        
        } else {
          
            jQuery('#pcctable tbody tr:has(td)').each(function () {
            
                var rowplugdata = jQuery.trim(jQuery(this).find('td:eq(4)').text());
           
                if (plugdata.toUpperCase() != 'ALL') {
                    if (rowplugdata == plugdata) {
                        jQuery(this).show();
                    } else {
                        jQuery(this).hide();
                    }
                } else if (jQuery(this).find('td:eq(4)').text() != '' || jQuery(this).find('td:eq(4)').text() != '') {
                   
                    if (plugdata != 'all') {
                        if (rowplugdata == plugdata) {
                            jQuery(this).show();
                        }
                        else {
                            jQuery(this).hide();
                        }
                    }
                }
 
            });
        }
    }