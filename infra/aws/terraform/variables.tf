variable "project_name" {
  description = "Short project name used in AWS resource names."
  type        = string
  default     = "konji-shop"
}

variable "environment" {
  description = "Deployment environment name, for example staging or production."
  type        = string
  default     = "staging"
}

variable "aws_region" {
  description = "AWS region for the deployment."
  type        = string
  default     = "eu-central-1"
}

variable "vpc_cidr" {
  description = "CIDR block for the application VPC."
  type        = string
  default     = "10.40.0.0/16"
}

variable "public_subnet_cidrs" {
  description = "CIDR blocks for public subnets. The EC2 instance is placed in the first subnet."
  type        = list(string)
  default     = ["10.40.1.0/24", "10.40.2.0/24"]
}

variable "private_db_subnet_cidrs" {
  description = "CIDR blocks for private RDS subnets. RDS requires at least two subnets in different availability zones."
  type        = list(string)
  default     = ["10.40.11.0/24", "10.40.12.0/24"]
}

variable "ssh_cidr_blocks" {
  description = "CIDR blocks allowed to SSH to the EC2 instance. Replace the default with your own IP before production use."
  type        = list(string)
  default     = ["0.0.0.0/0"]
}

variable "web_cidr_blocks" {
  description = "CIDR blocks allowed to access HTTP/HTTPS on the EC2 instance."
  type        = list(string)
  default     = ["0.0.0.0/0"]
}

variable "ssh_key_name" {
  description = "Existing EC2 key pair name for SSH access. Leave null if you will use AWS Systems Manager Session Manager only."
  type        = string
  default     = null
}

variable "instance_type" {
  description = "EC2 instance type for the Docker host."
  type        = string
  default     = "t3.small"
}

variable "root_volume_size_gb" {
  description = "Root EBS volume size in GB."
  type        = number
  default     = 30
}

variable "repository_url" {
  description = "Optional Git repository URL to clone into /var/www/konji-shop during bootstrap. Use an SSH URL for private repositories."
  type        = string
  default     = ""
}

variable "repository_branch" {
  description = "Git branch to clone when repository_url is provided."
  type        = string
  default     = "main"
}

variable "rds_instance_class" {
  description = "RDS MySQL instance class. Use db.t4g.small or larger for production."
  type        = string
  default     = "db.t4g.micro"
}

variable "rds_allocated_storage_gb" {
  description = "Initial RDS storage in GB."
  type        = number
  default     = 20
}

variable "rds_max_allocated_storage_gb" {
  description = "Maximum RDS autoscaled storage in GB."
  type        = number
  default     = 100
}

variable "rds_engine_version" {
  description = "RDS MySQL engine version."
  type        = string
  default     = "8.0"
}

variable "db_name" {
  description = "Application database name."
  type        = string
  default     = "konji_shop"
}

variable "db_username" {
  description = "Application database username."
  type        = string
  default     = "konji_shop"
}

variable "db_password" {
  description = "Application database password. If null or empty, Terraform generates one. This value is stored in Terraform state."
  type        = string
  default     = null
  sensitive   = true
}

variable "rds_backup_retention_days" {
  description = "RDS automated backup retention in days. Use at least 7 for production."
  type        = number
  default     = 7
}

variable "rds_deletion_protection" {
  description = "Protect the RDS instance from accidental deletion. Enable for production."
  type        = bool
  default     = false
}

variable "rds_skip_final_snapshot" {
  description = "Skip final DB snapshot when destroying RDS. Use false for production."
  type        = bool
  default     = true
}

variable "s3_bucket_name" {
  description = "Optional exact S3 bucket name. If null, Terraform creates a name with the current AWS account ID."
  type        = string
  default     = null
}

variable "route53_zone_id" {
  description = "Optional Route 53 hosted zone ID. When set with domain_name, Terraform creates an A record to the EC2 Elastic IP."
  type        = string
  default     = ""
}

variable "domain_name" {
  description = "Optional DNS name for the app, for example staging.example.pl. Used only when route53_zone_id is set."
  type        = string
  default     = ""
}

variable "extra_tags" {
  description = "Additional AWS tags to apply to resources."
  type        = map(string)
  default     = {}
}
