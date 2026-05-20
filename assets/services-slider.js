/* Services Slider - vanilla, namespaced, multiple instances supported */
(function () {
  class ServicesSlider {
    constructor(root) {
      this.root = root;
      this.viewport = root.querySelector('.services-slider__viewport');
      this.track = root.querySelector('.services-slider__track');
      this.cards = Array.from(root.querySelectorAll('.services-slider__card'));
      this.prev = root.querySelector('.services-slider__nav--prev');
      this.next = root.querySelector('.services-slider__nav--next');
      this.pager = root.querySelector('.services-slider__pager');
      this.GAP = 20; // must match CSS --gap
      this.itemsPerPage = 3;
      this.page = 0;
      this.pageCount = 0;
      this._bind();
      this.layout();
    }

    _bind() {
      this.prev.addEventListener('click', () => this.goToPage(this.page - 1));
      this.next.addEventListener('click', () => this.goToPage(this.page + 1));
      window.addEventListener('resize', () => window.requestAnimationFrame(() => this.layout()));
    }

    calcItemsPerPage() {
      this.itemsPerPage = window.matchMedia('(max-width: 720px)').matches ? 2 : 3;
    }

    setCardWidths() {
      const viewportW = this.viewport.getBoundingClientRect().width;
      const totalGap = this.GAP * (this.itemsPerPage - 1);
      const width = Math.floor((viewportW - totalGap) / this.itemsPerPage);
      this.cards.forEach(c => c.style.width = width + 'px');
    }

    buildPager() {
      this.pager.innerHTML = '';
      this.pageCount = Math.ceil(this.cards.length / this.itemsPerPage);
      for (let i = 0; i < this.pageCount; i++) {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = String(i + 1);
        b.setAttribute('aria-label', 'Go to page ' + (i + 1));
        b.addEventListener('click', () => this.goToPage(i));
        this.pager.appendChild(b);
      }
    }

    updatePager() {
      Array.from(this.pager.children).forEach((b, i) => {
        if (i === this.page) b.setAttribute('aria-current', 'page');
        else b.removeAttribute('aria-current');
      });
    }

    getPageWidth() {
      const first = this.cards[0];
      if (!first) return 0;
      const w = first.getBoundingClientRect().width;
      return w * this.itemsPerPage + this.GAP * (this.itemsPerPage - 1);
    }

  goToPage(i) {
  this.page = Math.max(0, Math.min(this.pageCount - 1, i));

  const targetIndex = this.page * this.itemsPerPage;
  const targetCard = this.cards[targetIndex];
  const offset = targetCard ? targetCard.offsetLeft : 0;

  this.track.style.transform = 'translateX(' + (-offset) + 'px)';

  this.updatePager();
  this.prev.disabled = this.page === 0;
  this.next.disabled = this.page === this.pageCount - 1;
}

    layout() {
      const before = this.itemsPerPage;
      this.calcItemsPerPage();
      this.setCardWidths();
      this.buildPager();
      if (before !== this.itemsPerPage) this.page = 0;
      this.goToPage(this.page);
    }
  }

  // Auto-init all .services-slider on the page
  function initAll() {
    document.querySelectorAll('.services-slider').forEach(el => {
      if (!el.__servicesSlider) el.__servicesSlider = new ServicesSlider(el);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  // Expose constructor if manual control is desired
  window.ServicesSlider = ServicesSlider;
})();