function isBlank(s){
  if ((s === null) || (s.length === 0)){
    return true;
  }

  for (var i = 0; i < s.length; i++){
    var c = s.charAt(i);
	  if ((c != ' ') && (c != '\n') && (c != '\t')){
	    return false;
    }
  }
  return true;
}

function validate(frm){
  if (isBlank(frm.mail.value) || frm.mail.value.indexOf("@") == -1){
    frm.mail.focus();
    return false;
  }
  if (isBlank(frm.name.value)){
    frm.name.focus();
    return false;
  }

  if (isBlank(frm.text.value)){
    frm.text.focus();
    return false;
	}
}

/**
 * AJAX preview
 *
 * @author Michael Klier <chi@chimeric.de>
 */
function discussion_ajax_preview() {
    if(!document.getElementById) return;

    var textarea = $('discussion__comment_text');
    var comment = textarea.value;
    if(!comment) return;

    var preview = $('discussion__comment_preview');
    var wikisyntaxok = $('discussion__comment_wikisyntaxok');

    // We use SACK to do the AJAX requests
    var ajax = new sack(DOKU_BASE+'lib/plugins/discussion/ajax.php');
    ajax_qsearch.sack.AjaxFailedAlert = '';
    ajax_qsearch.sack.encodeURIString = false;

    // define callback
    ajax.onCompletion = function(){
        var data = this.response;
        if(data === ''){ return; }
        preview.style.visibility = 'hidden';
        preview.innerHTML = data;
        preview.style.visibility = 'visible';
    };

    ajax.runAJAX('comment='+comment+'&wikisyntaxok='+wikisyntaxok.value);
}

// init toolbar
addInitEvent(function() {initToolbar("discussion__comment_toolbar", "discussion__comment_text", toolbar)});

// init preview button
addInitEvent(function() {
    var btn = $('discussion__btn_preview');
    if(!btn) return;
    addEvent(btn, 'click', discussion_ajax_preview);
});
