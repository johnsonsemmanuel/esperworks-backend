FROM php:8.2-cli

WORKDIR /var/www/html

# Install system dependencies, Chromium for Browsershot, and fonts
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libonig-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    # Chromium and dependencies for Browsershot PDF generation
    chromium \
    libatk-bridge2.0-0 \
    libdrm2 \
    libxkbcommon0 \
    libxcomposite1 \
    libxdamage1 \
    libxrandr2 \
    libgbm1 \
    libpango-1.0-0 \
    libcairo2 \
    libasound2 \
    libatspi2.0-0 \
    libnspr4 \
    libnss3 \
    libxss1 \
    # Fonts for professional PDF rendering
    fonts-liberation \
    fonts-noto \
    fonts-noto-cjk \
    fontconfig \
    # Node.js for Puppeteer
    nodejs \
    npm \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring zip bcmath gd \
    && rm -rf /var/lib/apt/lists/*

# Set Chromium path for Browsershot (no sandbox needed in container)
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
ENV CHROMIUM_PATH=/usr/bin/chromium

# Install Puppeteer globally (Browsershot dependency)
RUN npm install -g puppeteer@latest --unsafe-perm=true

# Install Google Fonts (Inter) for professional document rendering
# Using GitHub mirror as Google Fonts download API no longer returns a direct zip
RUN mkdir -p /usr/share/fonts/truetype/inter && \
    curl -sL "https://github.com/google/fonts/raw/main/ofl/inter/Inter%5Bslnt%2Cwght%5D.ttf" \
        -o /usr/share/fonts/truetype/inter/Inter.ttf && \
    fc-cache -f -v

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy ALL application code first (including artisan file)
COPY . .

# Install composer dependencies without running post-install scripts
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --optimize-autoloader

# Run Laravel-specific commands after all files are present
RUN php artisan package:discover --ansi
RUN php artisan storage:link || true

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

EXPOSE 8000

CMD ["sh", "start.sh"]

