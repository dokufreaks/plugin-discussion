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