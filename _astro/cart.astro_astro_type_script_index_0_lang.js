import{c as g,g as v,b as f,a as k,r as h,u as p}from"./cart.js";const r=()=>{const i=document.getElementById("cart-page-items"),o=document.getElementById("cart-page-count"),u=document.getElementById("cart-page-total");if(!i)return;const c=v(),a=f(),m=k();let d=0;const l=document.querySelector('input[name="delivery-option"]:checked');if(l){const e=l.value;if(e==="minsk")d=30,localStorage.setItem("cart-delivery",JSON.stringify({type:e,title:"Доставка по Минску",price:30,km:0}));else if(e==="suburb"){const t=document.getElementById("delivery-km"),n=t&&parseInt(t.value)||5;d=30+2*n,localStorage.setItem("cart-delivery",JSON.stringify({type:e,title:`Доставка за МКАД (${n} км)`,price:d,km:n}))}else e==="europochta"?(d=60,localStorage.setItem("cart-delivery",JSON.stringify({type:e,title:"Доставка Европочтой",price:60,km:0}))):localStorage.removeItem("cart-delivery")}else localStorage.removeItem("cart-delivery");if(o&&(o.textContent=a.toString()),u&&(u.textContent=`${m+d} BYN`),c.length===0){i.innerHTML=`
        <div class="py-8 text-center">
          <p class="mb-6 text-lg text-text-light dark:text-darkmode-text-light">Ваша корзина пуста</p>
          <a href="/store" class="btn btn-primary">Перейти в магазин</a>
        </div>
      `;return}i.innerHTML=c.map(e=>`
        <div class="mb-6 flex flex-col border-b border-border pb-6 last:mb-0 last:border-0 last:pb-0 dark:border-darkmode-border">
          <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
              <div class="flex-1">
                <h4 class="mb-1 ${e.id.startsWith("service-")||e.id.includes("usluga")?"text-xl text-primary":"text-lg"} font-semibold">${e.title}</h4>
                <p class="mb-0 text-sm text-text-light dark:text-darkmode-text-light">${e.price} BYN за шт.</p>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:24px;">
              <div class="qty-control">
                <button class="qty-btn" data-dec="${e.id}">-</button>
                <input 
                  type="number" 
                  value="${e.quantity}" 
                  min="1" 
                  class="qty-input" 
                  data-qty-input="${e.id}"
                />
                <button class="qty-btn" data-inc="${e.id}">+</button>
              </div>
              <div style="min-width:80px;text-align:right;font-weight:700;">
                ${e.price*e.quantity} BYN
              </div>
              <button class="text-red-500 hover:text-red-700 transition" aria-label="Удалить" data-remove="${e.id}">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18m-2 0v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6m3 0V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
              </button>
            </div>
          </div>
        </div>
      `).join(""),i.querySelectorAll("[data-remove]").forEach(e=>{e.addEventListener("click",()=>{h(e.dataset.remove||""),r()})}),i.querySelectorAll("[data-inc]").forEach(e=>{e.addEventListener("click",()=>{const t=e.dataset.inc||"",n=v().find(s=>s.id===t);n&&p(t,n.quantity+1),r()})}),i.querySelectorAll("[data-dec]").forEach(e=>{e.addEventListener("click",()=>{const t=e.dataset.dec||"",n=v().find(s=>s.id===t);n&&p(t,n.quantity-1),r()})}),i.querySelectorAll("[data-qty-input]").forEach(e=>{e.addEventListener("change",t=>{const n=e.dataset.qtyInput||"",s=parseInt(t.target.value);p(n,s),r()})})},y=()=>{if(window._cartInitialized)return;window._cartInitialized=!0;const i=document.getElementById("clear-cart-page");i&&i.addEventListener("click",()=>{g(),localStorage.removeItem("cart-delivery");const e=document.querySelector('input[name="delivery-option"][value="pickup"]');e&&(e.checked=!0);const t=document.getElementById("km-selector-wrapper");t&&t.classList.add("hidden"),r()});const o=document.getElementById("checkout-btn");o&&o.addEventListener("click",()=>{document.dispatchEvent(new CustomEvent("checkout:open"))});const u=document.querySelectorAll('input[name="delivery-option"]'),c=document.getElementById("km-selector-wrapper");u.forEach(e=>{e.addEventListener("change",t=>{t.target.value==="suburb"?c&&c.classList.remove("hidden"):c&&c.classList.add("hidden"),r()})});const a=document.getElementById("delivery-km"),m=document.getElementById("km-btn-dec"),d=document.getElementById("km-btn-inc");m&&a&&m.addEventListener("click",()=>{const e=parseInt(a.value)||5;e>1&&(a.value=(e-1).toString(),r())}),d&&a&&d.addEventListener("click",()=>{const e=parseInt(a.value)||5;a.value=(e+1).toString(),r()});const l=localStorage.getItem("cart-delivery");if(l)try{const e=JSON.parse(l),t=document.querySelector(`input[name="delivery-option"][value="${e.type}"]`);t&&(t.checked=!0,e.type==="suburb"&&(a&&e.km&&(a.value=e.km.toString()),c&&c.classList.remove("hidden")))}catch(e){console.error("Error restoring delivery selection",e)}else{const e=document.querySelector('input[name="delivery-option"][value="pickup"]');e&&(e.checked=!0)}r(),window.addEventListener("cart:update",r),document.addEventListener("cart:update",r)};document.readyState==="loading"?document.addEventListener("DOMContentLoaded",y):y();window.addEventListener("pageshow",()=>{y()});
