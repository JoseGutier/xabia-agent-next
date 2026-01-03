// frontend/chat/ui-lightbox.js
(function () {

  if (!window.Xabia) window.Xabia = {};
  const X = window.Xabia;

  X.Lightbox = {
    container: null,
    currentIndex: 0,
    images: [],

    init() {
      if (this.container) return;

      this.container = document.createElement('div');
      this.container.id = 'xabia-lightbox';
      this.container.innerHTML = `
        <div class="xabia-lightbox-backdrop"></div>
        <img class="xabia-lightbox-img" src="" />
      `;

      document.body.appendChild(this.container);

      // Solo cerrar si se hace clic en el fondo
      this.container.querySelector('.xabia-lightbox-backdrop')
        .addEventListener('click', () => this.close());

      // Navegación + ESC
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') this.close();
        if (e.key === 'ArrowRight') this.next();
        if (e.key === 'ArrowLeft')  this.prev();
      });
    },

    open(images, index = 0) {
      this.init();

      this.images = images || [];
      this.currentIndex = index;

      if (!this.images.length) return;

      this.updateImage();
      this.container.classList.add('open');
    },

    close() {
      this.container?.classList.remove('open');
    },

    next() {
      if (this.images.length < 2) return;
      this.currentIndex = (this.currentIndex + 1) % this.images.length;
      this.updateImage();
    },

    prev() {
      if (this.images.length < 2) return;
      this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length;
      this.updateImage();
    },

    updateImage() {
      const img = this.container.querySelector('.xabia-lightbox-img');
      img.src = this.images[this.currentIndex];
    }
  };

})();