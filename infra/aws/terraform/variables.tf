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
  description = "EC2 instance type for the Docker host. t3a.small is the cost-conscious x86 MVP default and can use the existing amd64 Ubuntu/Docker build."
  type        = string
  default     = "t3a.small"
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
  description = "RDS MySQL instance class. Start the MVP at db.t4g.micro and scale only from measured CPU, memory, connections, and latency."
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

variable "rds_cloudwatch_log_exports" {
  description = "RDS logs exported to CloudWatch. Keep the MVP default to error only; temporarily add slowquery when profiling."
  type        = list(string)
  default     = ["error"]

  validation {
    condition = alltrue([
      for log_name in var.rds_cloudwatch_log_exports : contains(["error", "general", "slowquery"], log_name)
    ])
    error_message = "rds_cloudwatch_log_exports may contain only error, general, or slowquery."
  }
}

variable "cloudwatch_log_retention_days" {
  description = "Retention for Terraform-managed RDS CloudWatch log groups. Avoid CloudWatch's indefinite-retention default."
  type        = number
  default     = 14

  validation {
    condition     = contains([1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1096, 1827, 2192, 2557, 2922, 3288, 3653], var.cloudwatch_log_retention_days)
    error_message = "cloudwatch_log_retention_days must be a CloudWatch Logs supported retention value."
  }
}

variable "s3_noncurrent_version_retention_days" {
  description = "Days to retain noncurrent S3 object versions for rollback before lifecycle deletion."
  type        = number
  default     = 30

  validation {
    condition     = var.s3_noncurrent_version_retention_days >= 1
    error_message = "s3_noncurrent_version_retention_days must be at least 1."
  }
}

variable "s3_abort_incomplete_multipart_days" {
  description = "Days before S3 aborts incomplete multipart uploads and stops billing for abandoned parts."
  type        = number
  default     = 7

  validation {
    condition     = var.s3_abort_incomplete_multipart_days >= 1
    error_message = "s3_abort_incomplete_multipart_days must be at least 1."
  }
}

variable "monthly_cost_budget_usd" {
  description = "Monthly AWS cost budget threshold in USD. A budget is created only when cost_budget_notification_email is set."
  type        = number
  default     = 60

  validation {
    condition     = var.monthly_cost_budget_usd > 0
    error_message = "monthly_cost_budget_usd must be greater than zero."
  }
}

variable "cost_budget_notification_email" {
  description = "Email for free AWS Budget alerts. Leave empty to skip budget creation."
  type        = string
  default     = ""
}

variable "s3_bucket_name" {
  description = "Optional exact S3 bucket name. If null, Terraform creates a name with the current AWS account ID."
  type        = string
  default     = null
}

variable "cloudfront_price_class" {
  description = "CloudFront price class for public catalogue media. PriceClass_100 keeps the MVP edge footprint to North America and Europe/Israel."
  type        = string
  default     = "PriceClass_100"

  validation {
    condition     = contains(["PriceClass_100", "PriceClass_200", "PriceClass_All"], var.cloudfront_price_class)
    error_message = "cloudfront_price_class must be PriceClass_100, PriceClass_200, or PriceClass_All."
  }
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
