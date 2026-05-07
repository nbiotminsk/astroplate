import{c as B,g as l,b as S,a as A,r as M,d as N,u as v}from"./cart.9l95F7No.js";const g=[{id:"replacement",price:80,title:"Услуга: Замена счетчика на объекте"},{id:"warranty",price:100,title:"Услуга: Расширенная гарантия 24 мес"},{id:"battery",price:40,title:"Дополнительная батарейка"}],d=()=>{const n=document.getElementById("cart-page-items"),c=document.getElementById("cart-page-count"),m=document.getElementById("cart-page-total");if(!n)return;const u=l(),x=S(),y=A();if(c&&(c.textContent=x.toString()),m&&(m.textContent=`${y} BYN`),u.length===0){n.innerHTML=`
        <div class="py-8 text-center">
          <p class="mb-6 text-lg text-text-light dark:text-darkmode-text-light">Ваша корзина пуста</p>
          <a href="/store" class="btn btn-primary">Перейти в магазин</a>
        </div>
      `;return}n.innerHTML=u.map(t=>{const a=t.id.startsWith("service-")||t.id.includes("usluga");let e="";if(!a){const r=g.filter(i=>{const s=`service-${i.id}-${t.id}`;if(u.some(C=>C.id===s))return!1;const p=t.category||"",h=p.includes("модуль NBIoT"),k=p.includes("Счетчики воды"),w=p.includes("комплекты"),o=t.title.toLowerCase(),b=t.id.toLowerCase(),E=o.includes("nbiot")||b.includes("nbiot"),I=o.includes("счетчик")||o.includes("счётчик")||b.includes("schetchik"),L=o.includes("комплект"),$=h||E,q=k||I;return w||L?!0:$?i.id!=="replacement":q?i.id==="replacement":!0});r.length>0&&(e=`
              <div class="mt-4 border-t border-dashed border-border pt-3 dark:border-darkmode-border">
                <p class="mb-2 text-xs font-bold uppercase tracking-wider text-text-light dark:text-darkmode-text-light">Добавить услуги для этого товара:</p>
                <div class="flex flex-wrap gap-2">
                  ${r.map(i=>`
                      <button 
                        class="btn btn-outline-primary btn-sm px-3 py-1 text-sm font-medium" 
                        data-add-service="${i.id}" 
                        data-parent-id="${t.id}"
                      >
                        + ${i.title} (${i.price} р.)
                      </button>
                    `).join("")}
                </div>
              </div>
            `)}return`
        <div class="mb-6 flex flex-col border-b border-border pb-6 last:mb-0 last:border-0 last:pb-0 dark:border-darkmode-border">
          <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
              <div class="flex-1">
                <h4 class="mb-1 ${a?"text-xl text-primary":"text-lg"} font-semibold">${t.title}</h4>
                <p class="mb-0 text-sm text-text-light dark:text-darkmode-text-light">${t.price} BYN за шт.</p>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:24px;">
              <div class="qty-control">
                <button class="qty-btn" data-dec="${t.id}">-</button>
                <input 
                  type="number" 
                  value="${t.quantity}" 
                  min="1" 
                  class="qty-input" 
                  data-qty-input="${t.id}"
                />
                <button class="qty-btn" data-inc="${t.id}">+</button>
              </div>
              <div style="min-width:80px;text-align:right;font-weight:700;">
                ${t.price*t.quantity} BYN
              </div>
              <button class="text-red-500 hover:text-red-700 transition" aria-label="Удалить" data-remove="${t.id}">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18m-2 0v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6m3 0V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
              </button>
            </div>
          </div>
          ${e}
        </div>
      `}).join(""),n.querySelectorAll("[data-remove]").forEach(t=>{t.addEventListener("click",()=>{M(t.dataset.remove||""),d()})}),n.querySelectorAll("[data-add-service]").forEach(t=>{t.addEventListener("click",()=>{const a=t.dataset.addService||"",e=t.dataset.parentId||"",r=l().find(s=>s.id===e),i=g.find(s=>s.id===a);r&&i&&(N({id:`service-${i.id}-${e}`,title:i.title,price:i.price,quantity:r.quantity}),d())})}),n.querySelectorAll("[data-inc]").forEach(t=>{t.addEventListener("click",()=>{const a=t.dataset.inc||"",e=l().find(r=>r.id===a);e&&v(a,e.quantity+1),d()})}),n.querySelectorAll("[data-dec]").forEach(t=>{t.addEventListener("click",()=>{const a=t.dataset.dec||"",e=l().find(r=>r.id===a);e&&v(a,e.quantity-1),d()})}),n.querySelectorAll("[data-qty-input]").forEach(t=>{t.addEventListener("change",a=>{const e=t.dataset.qtyInput||"",r=parseInt(a.target.value);v(e,r),d()})})},f=()=>{if(window._cartInitialized)return;window._cartInitialized=!0;const n=document.getElementById("clear-cart-page");n&&n.addEventListener("click",()=>{B(),d()});const c=document.getElementById("checkout-btn");c&&c.addEventListener("click",()=>{document.dispatchEvent(new CustomEvent("checkout:open"))}),d(),window.addEventListener("cart:update",d),document.addEventListener("cart:update",d)};document.readyState==="loading"?document.addEventListener("DOMContentLoaded",f):f();window.addEventListener("pageshow",()=>{f()});
