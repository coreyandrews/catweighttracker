# Use an official PHP image with Apache
FROM php:8.2-apache

# Install necessary system packages for SQLite development headers
# apt-get update refreshes the package list
# libsqlite3-dev provides the development files for SQLite
RUN apt-get update && apt-get install -y libsqlite3-dev \
    # Clean up apt caches to keep the image size down
    && rm -rf /var/lib/apt/lists/*

# Install PDO and PDO SQLite extensions
# These are necessary for PHP to interact with the SQLite database
RUN docker-php-ext-install pdo pdo_sqlite

# Set the working directory inside the container
WORKDIR /var/www/html

# Copy the application files from your local directory to the container's web root
COPY index.php .

# Create a directory for the SQLite database file
# This directory will be mounted as a volume to persist the database
RUN mkdir -p /var/www/html/data

# Set appropriate permissions for the data directory
# This ensures that the Apache user inside the container can write to it
RUN chown -R www-data:www-data /var/www/html/data
RUN chmod -R 775 /var/www/html/data

# Expose port 80, which Apache listens on by default
EXPOSE 80
