(function($){
  $(document).on('click', '#pcc-rescan', function(){
    var $btn = $(this);
    var orig = $btn.text();
    $btn.prop('disabled', true).text('Rescanning...');
    $.post(PCCVars.ajaxUrl, {
      action: PCCVars.action, // 'pcc_rescan_now'
      nonce:  PCCVars.nonce
    }).done(function(){
      location.reload(); // reload to fetch fresh cached results
    }).fail(function(){
      alert('Rescan failed. Please try again.');
      $btn.prop('disabled', false).text(orig);
    });
  });
})(jQuery);