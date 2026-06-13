output "app_public_ip" {
  description = "Elastic IP address assigned to the EC2 app host."
  value       = aws_eip.app.public_ip
}

output "app_public_dns" {
  description = "Public DNS name of the EC2 app host."
  value       = aws_instance.app.public_dns
}

output "ssh_command" {
  description = "SSH command for the EC2 app host."
  value       = "ssh ubuntu@${aws_eip.app.public_ip}"
}

output "rds_endpoint" {
  description = "RDS MySQL endpoint without port. Use this as DB_HOST in Laravel."
  value       = aws_db_instance.mysql.address
}

output "rds_port" {
  description = "RDS MySQL port."
  value       = aws_db_instance.mysql.port
}

output "db_name" {
  description = "Laravel DB_DATABASE value."
  value       = var.db_name
}

output "db_username" {
  description = "Laravel DB_USERNAME value."
  value       = var.db_username
}

output "db_password" {
  description = "Laravel DB_PASSWORD value. Sensitive output; also stored in Terraform state."
  value       = local.db_password
  sensitive   = true
}

output "s3_bucket" {
  description = "Laravel AWS_BUCKET value."
  value       = aws_s3_bucket.uploads.bucket
}

output "s3_region" {
  description = "Laravel AWS_DEFAULT_REGION value."
  value       = var.aws_region
}

output "route53_record" {
  description = "Route 53 record created for the app, if configured."
  value       = var.route53_zone_id != "" && var.domain_name != "" ? var.domain_name : null
}
