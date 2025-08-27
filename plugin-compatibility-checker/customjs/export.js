(function(){
  document.getElementById('exportButton')?.addEventListener('click', function(){
    var rows = (window.PCCExportData && Array.isArray(window.PCCExportData) ? window.PCCExportData : window.dataArray) || [];
    if (!rows.length) return;

    var keys = Object.keys(rows[0]);
    var csv  = [ keys.join(',') ];
    rows.forEach(function(row){
      csv.push(keys.map(function(k){
        var v = (row[k] == null) ? '' : String(row[k]);
        if (v.indexOf(',')>-1 || v.indexOf('"')>-1 || v.indexOf('\n')>-1) v = '"' + v.replace(/"/g,'""') + '"';
        return v;
      }).join(','));
    });

    var blob = new Blob([csv.join('\r\n')], {type:'text/csv;charset=utf-8;'});
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href   = url;
    a.download = 'plugin-compatibility-export.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  });
})();