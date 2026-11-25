<?php
require_once __DIR__ . '/auth.php';
function pageHead($title) {
  if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
  }
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?=h($title)?> · DISC UAT</title><link rel="stylesheet" href="<?=appUrl('uat.css?v=workflow')?>"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css"></head><body>
<div class="uat-banner">⚠️ ระบบทดสอบ — ข้อมูลชุดทดสอบแยกจากระบบ Production</div>
<nav><a class="brand" href="<?=appUrl('index.php')?>">⚖️ DISC UAT</a><?php if(isUatSuperAdmin() || (($_SESSION['user_role']??'')==='admin')): ?><a href="<?=appUrl('admin.php')?>">Dashboard</a><?php endif; ?><span><?=h($_SESSION['user_name']??'')?></span><a href="<?=appUrl('logout.php')?>">ออกจากระบบ</a></nav><main>
<?php }
function pageFoot() { ?>
</main><script src="https://cdn.jsdelivr.net/npm/flatpickr"></script><script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/th.js"></script><script>
function buddhistFormat(date, format, locale){return format==='j F Y'?date.getDate()+' '+locale.months.longhand[date.getMonth()]+' '+(date.getFullYear()+543):flatpickr.formatDate(date,format,locale)}
if (typeof flatpickr === 'function') flatpickr('input[type=date]:not([name=start_date])',{locale:'th',dateFormat:'Y-m-d',altInput:true,altFormat:'j F Y',disableMobile:true,formatDate:buddhistFormat,onReady:(_,__,i)=>{let y=i.calendarContainer.querySelector('.cur-year');if(y){y.value=i.currentYear+543;y.readOnly=true}},onMonthChange:(_,__,i)=>{let y=i.calendarContainer.querySelector('.cur-year');if(y)y.value=i.currentYear+543}});
function simplifySubjectInputs(){const root=document.getElementById('subjects');if(!root)return;root.querySelectorAll('.subject').forEach(card=>{for(const key of ['title','position']){const input=card.querySelector('[name$="['+key+']"]');if(!input)continue;input.required=false;const field=input.closest('div');if(field)field.hidden=true;}const first=card.querySelector('[name$="[first]"]');if(first)first.required=true;});}
document.addEventListener('DOMContentLoaded',()=>{simplifySubjectInputs();const root=document.getElementById('subjects');if(root)new MutationObserver(simplifySubjectInputs).observe(root,{childList:true,subtree:true});});
</script></body></html>
<?php }
