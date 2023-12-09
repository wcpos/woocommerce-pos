<div align="center">
  <h1><a href="https://wcpos.com">WooCommerce POS</a> plugin</h1>
  <p>A WordPress plugin for taking WooCommerce orders at the Point of Sale.</p>
  <p>
    <a href="https://github.com/wcpos/woocommerce-pos/actions/workflows/tests.yml">
      <img src="https://github.com/wcpos/woocommerce-pos/actions/workflows/tests.yml/badge.svg" alt="Tests" />
    </a>
    <a href="https://wcpos.github.io/woocommerce-pos">
      <img src="https://github.com/wcpos/woocommerce-pos/actions/workflows/build-docs.yml/badge.svg" alt="Hooks docs" />
    </a>
    <a href="https://wcposdev.wpengine.com/pos">
      <img src="https://github.com/wcpos/woocommerce-pos/actions/workflows/wp-engine.yml/badge.svg" alt="Deploy to WP Engine" />
    </a>
    <a href="https://wordpress.org/plugins/woocommerce-pos/">
      <img src="https://github.com/wcpos/woocommerce-pos/actions/workflows/wporg-deploy.yml/badge.svg" alt="Deploy to WordPress.org" />
    </a>
    <a href="https://wcpos.com/discord">
      <img src="https://img.shields.io/discord/711884517081612298?color=%237289DA&label=WCPOS&logo=discord&logoColor=white" alt="Discord chat" />
    </a>
  </p>
  <p>
    <a href="https://github.com/wcpos/woocommerce-pos#-structure"><b>About</b></a>
    &ensp;&mdash;&ensp;
    <a href="https://github.com/wcpos/woocommerce-pos#-structure"><b>Structure</b></a>
    &ensp;&mdash;&ensp;
    <a href="https://github.com/wcpos/woocommerce-pos#-workflows"><b>Workflows</b></a>
    &ensp;&mdash;&ensp;
    <a href="https://github.com/wcpos/woocommerce-pos#-how-to-use-it"><b>How to use it</b></a>
  </p>
</div>

## 💡 About

Coming soon.

## 📁 Structure

Coming soon.

## 👷 Workflows

We recommend [LocalWP](https://localwp.com/) for creating a WordPress install on your local machine. 
You can either clone the WooCommerce POS repository into the `/wp-content/plugins/` folder or create a symbolic link (recommended).

```sh
git clone https://github.com/wcpos/woocommerce-pos.git
```

To prepare the repository for local development you should rename `.env.example` to `.env`, this will set the local development flag to `true`.

Next you will need to install the required PHP via `composer` and JavaScript packages via `yarn`.

```sh
composer prefix-dependencies
composer install
yarn install
```

Once you have installed the required packages you can now start development. To build the settings page, use: 

```sh
 yarn settings start
```

## 🚀 How to use it

Coming soon.

