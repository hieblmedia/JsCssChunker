
$(document).ready(function() {
  // ...

  $('#toolbar-toggler a').click(function(){
    $('#toolbar-contents').slideToggle();
    return false;
  })
});