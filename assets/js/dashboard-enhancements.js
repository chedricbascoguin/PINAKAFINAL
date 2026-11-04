(() => {
  const initializeDashboardEnhancements = () => {
    document.body.classList.add("dashboard-enhanced");

    const setupStaffDrawer = () => {
      const header = document.querySelector("header");
      if (!header) return;
      const title = header.querySelector("h1")?.textContent?.trim() || "Staff Dashboard";
      if (/resident/i.test(title)) return;
      document.body.classList.add("staff-dashboard-enhanced");
      const headerLogout = header.querySelector("[data-staff-logout]");
      if (document.getElementById("navDrawer")) {
        const existingTrigger = header.querySelector('button[onclick*="navDrawer"]');
        if (existingTrigger && headerLogout) {
          const controls = document.createElement("div");
          controls.className = "staff-menu-logout-group";
          existingTrigger.parentElement?.insertBefore(controls, existingTrigger);
          controls.append(existingTrigger, headerLogout);
        }
        return;
      }
      const tabLinks = [...header.querySelectorAll('a[href*="tab="]')];
      if (tabLinks.length < 2) return;

      const sourceNavigation = tabLinks[0].parentElement;
      sourceNavigation?.classList.add("staff-source-nav-hidden");

      const trigger = document.createElement("button");
      trigger.type = "button";
      trigger.className = "staff-drawer-trigger";
      trigger.setAttribute("aria-label", "Open dashboard menu");
      trigger.setAttribute("aria-expanded", "false");
      trigger.innerHTML = '<span class="staff-drawer-bars" aria-hidden="true"><i></i><i></i><i></i></span><span>Menu</span>';

      const headerActions = header.querySelector(".justify-between") || header.firstElementChild || header;
      const controls = document.createElement("div");
      controls.className = "staff-menu-logout-group";
      controls.appendChild(trigger);
      if (headerLogout) controls.appendChild(headerLogout);
      headerActions.appendChild(controls);

      const drawer = document.createElement("div");
      drawer.id = "staffDashboardDrawer";
      drawer.className = "staff-drawer-shell";
      drawer.setAttribute("aria-hidden", "true");
      drawer.innerHTML = `
        <button type="button" class="staff-drawer-backdrop" aria-label="Close dashboard menu"></button>
        <aside class="staff-drawer-panel" role="dialog" aria-modal="true" aria-label="${title} navigation">
          <div class="staff-drawer-heading">
            <div><small>Navigation</small><h2></h2></div>
            <button type="button" class="staff-drawer-close" aria-label="Close menu">&times;</button>
          </div>
          <nav class="staff-drawer-links"></nav>
        </aside>`;
      drawer.querySelector("h2").textContent = title;
      const linkContainer = drawer.querySelector(".staff-drawer-links");
      tabLinks.forEach(original => {
        const link = document.createElement("a");
        link.href = original.href;
        link.innerHTML = original.innerHTML;
        link.className = "staff-drawer-link";
        if (original.className.match(/bg-white|font-bold/)) link.classList.add("is-active");
        linkContainer.appendChild(link);
      });

      document.body.appendChild(drawer);

      const closeDrawer = () => {
        drawer.classList.remove("is-open");
        drawer.setAttribute("aria-hidden", "true");
        trigger.setAttribute("aria-expanded", "false");
        document.body.classList.remove("staff-drawer-open");
      };
      const openDrawer = () => {
        drawer.classList.add("is-open");
        drawer.setAttribute("aria-hidden", "false");
        trigger.setAttribute("aria-expanded", "true");
        document.body.classList.add("staff-drawer-open");
        drawer.querySelector(".staff-drawer-close")?.focus();
      };
      trigger.addEventListener("click", openDrawer);
      drawer.querySelector(".staff-drawer-backdrop").addEventListener("click", closeDrawer);
      drawer.querySelector(".staff-drawer-close").addEventListener("click", closeDrawer);
      document.addEventListener("keydown", event => {
        if (event.key === "Escape" && drawer.classList.contains("is-open")) closeDrawer();
      });
    };
    setupStaffDrawer();

    if (document.body.classList.contains("staff-dashboard-enhanced")) {
      const interactiveCards = document.querySelectorAll(
        "main article, main section > .rounded-xl, main section > .rounded-2xl, main .dashboard-card"
      );
      interactiveCards.forEach(card => {
        card.classList.add("staff-interactive-card");
        card.addEventListener("pointermove", event => {
          const bounds = card.getBoundingClientRect();
          const x = event.clientX - bounds.left;
          const y = event.clientY - bounds.top;
          card.style.setProperty("--staff-pointer-x", `${x}px`);
          card.style.setProperty("--staff-pointer-y", `${y}px`);
          card.style.setProperty("--staff-tilt-x", `${((y / bounds.height) - .5) * -2.2}deg`);
          card.style.setProperty("--staff-tilt-y", `${((x / bounds.width) - .5) * 2.2}deg`);
        });
        card.addEventListener("pointerleave", () => {
          card.style.setProperty("--staff-tilt-x", "0deg");
          card.style.setProperty("--staff-tilt-y", "0deg");
        });
      });

      const chartBars = [...document.querySelectorAll('main [style*="width"]')].filter(element =>
        element.className.includes("h-2") || element.className.includes("h-2.5") || element.className.includes("h-3")
      );
      chartBars.forEach((bar, index) => {
        const percentage = Math.max(0, Math.min(100, parseFloat(bar.style.width) || 0));
        bar.classList.add("staff-chart-bar");
        bar.style.setProperty("--staff-chart-delay", `${Math.min(index * 65, 520)}ms`);
        bar.setAttribute("role", "progressbar");
        bar.setAttribute("aria-valuemin", "0");
        bar.setAttribute("aria-valuemax", "100");
        bar.setAttribute("aria-valuenow", String(Math.round(percentage)));
        bar.dataset.chartValue = `${Math.round(percentage)}%`;
        const track = bar.parentElement;
        track?.classList.add("staff-chart-track");
        track?.closest("section, article, .rounded-xl, .rounded-2xl")?.classList.add("staff-chart-3d");
      });

      const logoutLinks = document.querySelectorAll("[data-staff-logout]");
      if (logoutLinks.length) {
        const logoutDialog = document.createElement("div");
        logoutDialog.className = "staff-logout-shell";
        logoutDialog.id = "staffLogoutConfirmation";
        logoutDialog.setAttribute("aria-hidden", "true");
        logoutDialog.innerHTML = `
          <button type="button" class="staff-logout-backdrop" aria-label="Cancel logout"></button>
          <section class="staff-logout-dialog" role="dialog" aria-modal="true" aria-labelledby="staffLogoutTitle" aria-describedby="staffLogoutDescription">
            <div class="staff-logout-heading">
              <img class="staff-logout-brand" src="assets/images/resihunity_logo.jpg" alt="ResiHUnity">
              <span>STAFF PORTAL</span>
              <button type="button" class="staff-logout-close" aria-label="Close logout confirmation" title="Close">&times;</button>
            </div>
            <div class="staff-logout-content">
              <h2 id="staffLogoutTitle">Ready to log out?</h2>
              <p id="staffLogoutDescription">You will need to sign in again to return to your dashboard.</p>
            </div>
            <div class="staff-logout-actions">
              <button type="button" class="staff-logout-cancel">Stay signed in</button>
              <button type="button" class="staff-logout-confirm">Log out</button>
            </div>
          </section>`;
        document.body.appendChild(logoutDialog);
        let logoutDestination = "StaffLogout.php";
        let logoutTrigger = null;
        const cancelLogout = () => {
          logoutDialog.classList.remove("is-open");
          logoutDialog.setAttribute("aria-hidden", "true");
          document.body.classList.remove("staff-logout-open");
          logoutTrigger?.focus();
        };
        logoutLinks.forEach(link => link.addEventListener("click", event => {
          event.preventDefault();
          logoutDestination = link.href;
          logoutTrigger = link;
          logoutDialog.classList.add("is-open");
          logoutDialog.setAttribute("aria-hidden", "false");
          document.body.classList.add("staff-logout-open");
          logoutDialog.querySelector(".staff-logout-cancel")?.focus();
        }));
        logoutDialog.querySelector(".staff-logout-cancel").addEventListener("click", cancelLogout);
        logoutDialog.querySelector(".staff-logout-close").addEventListener("click", cancelLogout);
        logoutDialog.querySelector(".staff-logout-backdrop").addEventListener("click", cancelLogout);
        logoutDialog.querySelector(".staff-logout-confirm").addEventListener("click", () => {
          window.location.assign(logoutDestination);
        });
        document.addEventListener("keydown", event => {
          if (!logoutDialog.classList.contains("is-open")) return;
          if (event.key === "Escape") cancelLogout();
          if (event.key === "Tab") {
            const controls = [...logoutDialog.querySelectorAll('.staff-logout-dialog button')];
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
              event.preventDefault();
              last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
              event.preventDefault();
              first.focus();
            }
          }
        });
      }

      document.addEventListener("click", event => {
        const control = event.target.closest("button, a[href]");
        if (!control || control.closest(".staff-drawer-backdrop")) return;
        const bounds = control.getBoundingClientRect();
        const ripple = document.createElement("span");
        ripple.className = "staff-control-ripple";
        ripple.style.left = `${event.clientX - bounds.left}px`;
        ripple.style.top = `${event.clientY - bounds.top}px`;
        control.classList.add("staff-ripple-host");
        control.appendChild(ripple);
        ripple.addEventListener("animationend", () => ripple.remove(), { once: true });
      });
    }

    const progress = document.createElement("div");
    progress.className = "dashboard-progress";
    progress.setAttribute("aria-hidden", "true");
    document.body.appendChild(progress);

    const updateProgress = () => {
      const scrollable = document.documentElement.scrollHeight - window.innerHeight;
      const percentage = scrollable > 0 ? Math.min((window.scrollY / scrollable) * 100, 100) : 0;
      progress.style.width = `${percentage}%`;
    };
    updateProgress();
    window.addEventListener("scroll", updateProgress, { passive: true });
    window.addEventListener("resize", updateProgress);

    const targets = document.querySelectorAll(
      "main > section, main > div, [data-tab-panel] > article, [data-tab-panel] > div"
    );
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    if (!("IntersectionObserver" in window) || reducedMotion) {
      targets.forEach(target => target.classList.add("dashboard-visible"));
      return;
    }

    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add("dashboard-visible");
        observer.unobserve(entry.target);
      });
    }, { threshold: .06, rootMargin: "0px 0px -20px" });

    targets.forEach((target, index) => {
      target.classList.add("dashboard-reveal");
      target.style.transitionDelay = `${Math.min(index % 4, 3) * 45}ms`;
      observer.observe(target);
    });
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeDashboardEnhancements, { once: true });
  } else {
    initializeDashboardEnhancements();
  }
})();
