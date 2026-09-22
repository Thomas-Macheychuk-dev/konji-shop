terraform {
  backend "s3" {
    bucket       = "konji-shop-terraform-state-628263975265-eu-central-1"
    key          = "konji-shop/live/terraform.tfstate"
    region       = "eu-central-1"
    encrypt      = true
    use_lockfile = true
  }
}
